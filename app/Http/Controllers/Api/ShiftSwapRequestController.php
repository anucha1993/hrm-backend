<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ShiftSwapRequest;
use App\Services\ShiftSwapService;
use App\Services\WorkScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShiftSwapRequestController extends Controller
{
    public function __construct(
        private readonly ShiftSwapService $swapService,
        private readonly WorkScheduleService $scheduleService,
    ) {
    }

    private const RELATIONS = [
        'requester:id,employee_code,title,first_name,last_name,birth_date,department_id',
        'counterparty:id,employee_code,title,first_name,last_name,birth_date,department_id',
        'requesterShift:id,name,start_time,end_time',
        'counterpartyShift:id,name,start_time,end_time',
        'approver:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $q = ShiftSwapRequest::query()
            ->with(self::RELATIONS)
            ->latest();

        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }

        return response()->json(['data' => $q->limit(300)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'requester_id'      => ['required', 'exists:employees,id'],
            'counterparty_id'   => ['required', 'exists:employees,id'],
            'requester_date'    => ['required', 'date'],
            'counterparty_date' => ['nullable', 'date'],
            'reason'            => ['nullable', 'string', 'max:500'],
        ]);

        $requesterId = (int) $data['requester_id'];

        if ((int) $data['counterparty_id'] === $requesterId) {
            throw ValidationException::withMessages([
                'counterparty_id' => 'ไม่สามารถสลับกะให้พนักงานคนเดียวกันได้',
            ]);
        }

        $requesterDate = Carbon::parse($data['requester_date']);
        $counterpartyDate = ! empty($data['counterparty_date'])
            ? Carbon::parse($data['counterparty_date'])
            : $requesterDate->copy();

        $requester = Employee::findOrFail($requesterId);
        $counterparty = Employee::findOrFail($data['counterparty_id']);

        // snapshot กะเดิมของแต่ละฝ่าย ณ วันที่จะสลับ
        $requesterShift = $this->scheduleService->resolveShift($requester, $requesterDate);
        $counterpartyShift = $this->scheduleService->resolveShift($counterparty, $counterpartyDate);

        $swap = ShiftSwapRequest::create([
            'requester_id'          => $requesterId,
            'counterparty_id'       => $counterparty->id,
            'requester_date'        => $requesterDate->toDateString(),
            'counterparty_date'     => $counterpartyDate->toDateString(),
            'requester_shift_id'    => $requesterShift?->id,
            'counterparty_shift_id' => $counterpartyShift?->id,
            'reason'                => $data['reason'] ?? null,
            'status'                => 'pending',
            'created_by'            => $user->id,
        ]);

        return response()->json(['data' => $swap->load(self::RELATIONS)], 201);
    }

    public function approve(Request $request, ShiftSwapRequest $shiftSwapRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $result = $this->swapService->approve($shiftSwapRequest, $request->user()->id, $data['note'] ?? null);

        return response()->json(['data' => $result->load(self::RELATIONS)]);
    }

    public function reject(Request $request, ShiftSwapRequest $shiftSwapRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $result = $this->swapService->reject($shiftSwapRequest, $request->user()->id, $data['note'] ?? null);

        return response()->json(['data' => $result->load(self::RELATIONS)]);
    }

    public function cancel(ShiftSwapRequest $shiftSwapRequest): JsonResponse
    {
        $result = $this->swapService->cancel($shiftSwapRequest);

        return response()->json(['data' => $result->load(self::RELATIONS)]);
    }

    public function destroy(ShiftSwapRequest $shiftSwapRequest): JsonResponse
    {
        // ลบคำขอ (ถ้าอนุมัติแล้วให้ revert override ก่อน)
        $this->swapService->cancel($shiftSwapRequest);
        $shiftSwapRequest->delete();

        return response()->json(['message' => 'ลบเรียบร้อย']);
    }
}
