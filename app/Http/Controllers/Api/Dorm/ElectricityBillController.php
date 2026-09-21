<?php

namespace App\Http\Controllers\Api\Dorm;

use App\Http\Controllers\Controller;
use App\Models\ElectricityBill;
use App\Models\ElectricityBillItem;
use App\Services\Dorm\ElectricityBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ElectricityBillController extends Controller
{
    public function __construct(private ElectricityBillService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $q = ElectricityBill::query()->withCount('items')->orderByDesc('bill_month');
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }
        return response()->json(['data' => $q->paginate($request->integer('per_page', 20))]);
    }

    public function show(ElectricityBill $bill): JsonResponse
    {
        $bill->load(['items' => function ($q) {
            $q->with(['room', 'employee:id,employee_code,first_name,last_name', 'installments'])->orderBy('order');
        }, 'creator:id,name']);
        return response()->json(['data' => $bill]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bill_month' => ['required', 'date'],
            'rate_per_unit' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        $month = \Carbon\Carbon::parse($data['bill_month'])->startOfMonth()->toDateString();
        if (ElectricityBill::where('bill_month', $month)->exists()) {
            return response()->json(['message' => 'มีบิลของเดือนนี้อยู่แล้ว'], 422);
        }

        $bill = $this->service->create($data, $request->user()->id);
        return response()->json(['data' => $bill], 201);
    }

    public function syncRooms(ElectricityBill $bill): JsonResponse
    {
        try {
            $bill = $this->service->syncRooms($bill);
            return response()->json(['data' => $bill]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function updateItem(Request $request, ElectricityBill $bill, ElectricityBillItem $item): JsonResponse
    {
        if ($item->electricity_bill_id !== $bill->id) {
            return response()->json(['message' => 'ไม่พบรายการนี้ในบิลดังกล่าว'], 404);
        }

        $data = $request->validate([
            'meter_end' => ['nullable', 'numeric', 'min:0'],
            'room_rent' => ['nullable', 'numeric', 'min:0'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $item = $this->service->updateItem($item, $data);
            return response()->json(['data' => $item]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function finalize(Request $request, ElectricityBill $bill): JsonResponse
    {
        $data = $request->validate([
            'due_date_1' => ['required', 'date'],
            'due_date_2' => ['required', 'date'],
        ]);

        try {
            $bill = $this->service->finalize($bill, $data['due_date_1'], $data['due_date_2']);
            return response()->json(['data' => $bill]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(ElectricityBill $bill): JsonResponse
    {
        try {
            $this->service->destroy($bill);
            return response()->json(['message' => 'ลบเรียบร้อย']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
