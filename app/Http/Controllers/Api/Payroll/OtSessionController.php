<?php

namespace App\Http\Controllers\Api\Payroll;

use App\Exports\OtSessionsExport;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OtSession;
use App\Models\OtSessionEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OtSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = OtSession::with(['employees.employee:id,employee_code,first_name,last_name'])
            ->orderByDesc('ot_date');
        if ($from = $request->date('from')) {
            $q->whereDate('ot_date', '>=', $from);
        }
        if ($to = $request->date('to')) {
            $q->whereDate('ot_date', '<=', $to);
        }
        return response()->json(['data' => $q->paginate($request->integer('per_page', 20))]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $q = OtSession::with(['employees.employee:id,employee_code,first_name,last_name'])
            ->orderByDesc('ot_date');
        if ($from = $request->date('from')) $q->whereDate('ot_date', '>=', $from);
        if ($to = $request->date('to')) $q->whereDate('ot_date', '<=', $to);
        $records = $q->limit(10000)->get();
        return Excel::download(new OtSessionsExport($records), 'ot-sessions-' . now()->format('Ymd-Hi') . '.xlsx');
    }

    public function show(OtSession $otSession): JsonResponse
    {
        return response()->json(['data' => $otSession->load('employees.employee')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        return DB::transaction(function () use ($data, $request) {
            $session = OtSession::create(array_merge(
                collect($data)->except('employees')->toArray(),
                ['created_by' => $request->user()?->id]
            ));
            $this->syncEmployees($session, $data['employees'] ?? []);
            return response()->json(['data' => $session->load('employees.employee')], 201);
        });
    }

    public function update(Request $request, OtSession $otSession): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        return DB::transaction(function () use ($data, $otSession) {
            $otSession->update(collect($data)->except('employees')->toArray());
            if (array_key_exists('employees', $data)) {
                $this->syncEmployees($otSession, $data['employees']);
            }
            return response()->json(['data' => $otSession->fresh('employees.employee')]);
        });
    }

    public function destroy(OtSession $otSession): JsonResponse
    {
        if ($otSession->employees()->whereNotNull('payroll_slip_id')->exists()) {
            return response()->json(['message' => 'รอบ OT นี้ผูกกับสลิปแล้ว ลบไม่ได้'], 422);
        }
        $otSession->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    protected function rules(bool $update = false): array
    {
        $req = $update ? 'sometimes' : 'required';
        return [
            'ot_date' => [$req, 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'ot_type' => ['sometimes', Rule::in(['normal', 'holiday', 'holiday_overtime'])],
            'rate_mode' => ['sometimes', Rule::in(['hourly_amount', 'multiplier'])],
            'hourly_amount' => ['sometimes', 'numeric', 'min:0'],
            'multiplier' => ['sometimes', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['draft', 'open', 'closed'])],
            'employees' => ['sometimes', 'array'],
            'employees.*.employee_id' => [
                'required_with:employees', 'exists:employees,id',
                function ($attribute, $value, $fail) {
                    $employee = Employee::with('department:id,ot_eligible')->find($value);
                    if ($employee && $employee->department && ! $employee->department->ot_eligible) {
                        $fail("พนักงานรหัส {$employee->employee_code} อยู่แผนกที่ไม่อนุญาตให้มี OT");
                    }
                },
            ],
            'employees.*.hours' => ['required_with:employees', 'numeric', 'min:0'],
            'employees.*.note' => ['nullable', 'string'],
        ];
    }

    protected function syncEmployees(OtSession $session, array $rows): void
    {
        $keep = [];
        foreach ($rows as $r) {
            $rec = OtSessionEmployee::updateOrCreate(
                ['ot_session_id' => $session->id, 'employee_id' => $r['employee_id']],
                ['hours' => $r['hours'], 'note' => $r['note'] ?? null]
            );
            $keep[] = $rec->id;
        }
        OtSessionEmployee::where('ot_session_id', $session->id)
            ->whereNotIn('id', $keep)
            ->whereNull('payroll_slip_id')
            ->delete();
    }
}
