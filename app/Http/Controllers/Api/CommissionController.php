<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CommissionController extends Controller
{
    private const RELATIONS = ['employee:id,employee_code,first_name,last_name,nickname', 'payrollPeriod:id,name,code', 'creator:id,name'];

    public function index(Request $request): JsonResponse
    {
        $q = Commission::with(self::RELATIONS)->orderByDesc('earned_date')->orderByDesc('id');

        if ($id = $request->integer('employee_id')) $q->where('employee_id', $id);
        if ($status = $request->string('status')->toString()) $q->where('status', $status);
        if ($from = $request->string('from')->toString()) $q->whereDate('earned_date', '>=', $from);
        if ($to = $request->string('to')->toString())     $q->whereDate('earned_date', '<=', $to);

        if ($s = $request->string('search')->toString()) {
            $q->where(function ($w) use ($s) {
                $w->where('code', 'like', "%{$s}%")
                  ->orWhere('title', 'like', "%{$s}%")
                  ->orWhereHas('employee', function ($e) use ($s) {
                      $e->where('employee_code', 'like', "%{$s}%")
                        ->orWhere('first_name', 'like', "%{$s}%")
                        ->orWhere('last_name', 'like', "%{$s}%");
                  });
            });
        }

        return response()->json([
            'data'    => $q->paginate($request->integer('per_page', 20)),
            'summary' => [
                'pending_total' => (float) Commission::where('status', Commission::STATUS_PENDING)->sum('amount'),
            ],
        ]);
    }

    public function show(Commission $commission): JsonResponse
    {
        return response()->json(['data' => $commission->load([...self::RELATIONS, 'payslip:id,slip_no'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $earnedDate = Carbon::parse($data['earned_date']);

        $commission = Commission::create([
            'code'        => Commission::generateCode($earnedDate),
            'employee_id' => $data['employee_id'],
            'earned_date' => $data['earned_date'],
            'amount'      => $data['amount'],
            'title'       => $data['title'] ?? null,
            'note'        => $data['note'] ?? null,
            'status'      => Commission::STATUS_PENDING,
            'created_by'  => $request->user()?->id,
        ]);

        return response()->json(['data' => $commission->load(self::RELATIONS)], 201);
    }

    public function update(Request $request, Commission $commission): JsonResponse
    {
        if ($commission->status !== Commission::STATUS_PENDING) {
            return response()->json(['message' => 'รายการนี้ถูกตัดเข้า payroll แล้ว หรือถูกยกเลิกแล้ว ไม่สามารถแก้ไขได้'], 422);
        }

        $data = $this->validateData($request);

        $commission->update([
            'employee_id' => $data['employee_id'],
            'earned_date' => $data['earned_date'],
            'amount'      => $data['amount'],
            'title'       => $data['title'] ?? null,
            'note'        => $data['note'] ?? null,
        ]);

        return response()->json(['data' => $commission->fresh()->load(self::RELATIONS)]);
    }

    public function destroy(Commission $commission): JsonResponse
    {
        if ($commission->status === Commission::STATUS_PAID) {
            return response()->json(['message' => 'รายการนี้ถูกตัดเข้า payroll แล้ว ไม่สามารถลบได้ — ให้ใช้สถานะ cancelled แทน'], 422);
        }
        $commission->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    /**
     * เปลี่ยนสถานะ (cancel เท่านั้น — ยังไม่ตัดเข้า payroll)
     * POST /api/commissions/{commission}/status
     */
    public function changeStatus(Request $request, Commission $commission): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'cancelled'])],
        ]);

        if ($commission->status === Commission::STATUS_PAID) {
            return response()->json(['message' => 'รายการนี้ถูกตัดเข้า payroll แล้ว ไม่สามารถเปลี่ยนสถานะได้'], 422);
        }

        $commission->update(['status' => $data['status']]);

        return response()->json(['data' => $commission->fresh()->load(self::RELATIONS)]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'earned_date' => ['required', 'date'],
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'title'       => ['nullable', 'string', 'max:255'],
            'note'        => ['nullable', 'string'],
        ]);
    }
}
