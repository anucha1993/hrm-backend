<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollRule;
use App\Models\PayrollSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayrollRuleController extends Controller
{
    private const ALLOWED_TRIGGERS = [
        'late_count', 'late_minutes', 'absent_count', 'early_leave_count',
        'missing_punch', 'no_disqualifier', 'rating_avg', 'tenure_years',
        'ot_hours', 'leave_over_quota',
    ];

    private const ALLOWED_DISQUALIFIERS = [
        'absent', 'late', 'early_leave', 'missing_punch',
        'leave_sick', 'leave_personal', 'leave_vacation', 'leave_maternity', 'leave_other',
        'holiday_absent',
    ];

    public function index(Request $request): JsonResponse
    {
        $q = PayrollRule::query()->orderBy('type')->orderBy('priority')->orderBy('id');

        if ($type = $request->string('type')->toString()) {
            $q->where('type', $type);
        }
        if ($request->filled('active')) {
            $q->where('active', $request->boolean('active'));
        }
        if ($s = $request->string('search')->toString()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%"));
        }

        return response()->json(['data' => $q->get()]);
    }

    public function show(PayrollRule $rule): JsonResponse
    {
        return response()->json(['data' => $rule]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $data['code'] = $data['code'] ?? PayrollRule::generateCode($data['type']);
        $data['created_by'] = $request->user()?->id;
        $data['updated_by'] = $request->user()?->id;

        $rule = PayrollRule::create($data);
        return response()->json(['data' => $rule], 201);
    }

    public function update(Request $request, PayrollRule $rule): JsonResponse
    {
        $data = $this->validateData($request, $rule->id);
        $data['updated_by'] = $request->user()?->id;
        $rule->update($data);
        return response()->json(['data' => $rule->fresh()]);
    }

    public function destroy(PayrollRule $rule): JsonResponse
    {
        $rule->delete();
        return response()->json(['data' => true]);
    }

    public function toggle(Request $request, PayrollRule $rule): JsonResponse
    {
        $rule->update([
            'active' => ! $rule->active,
            'updated_by' => $request->user()?->id,
        ]);
        return response()->json(['data' => $rule->fresh()]);
    }

    /* =================== Settings (global caps) =================== */

    public function settingsIndex(): JsonResponse
    {
        $items = PayrollSetting::orderBy('category')->orderBy('key')->get();
        return response()->json(['data' => $items]);
    }

    public function settingsBulkUpdate(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'items'              => ['required', 'array'],
            'items.*.key'        => ['required', 'string', 'max:100'],
            'items.*.value'      => ['nullable'],
            'items.*.category'   => ['nullable', 'string', 'max:50'],
            'items.*.label'      => ['nullable', 'string', 'max:200'],
        ]);

        $userId = $request->user()?->id;
        DB::transaction(function () use ($payload, $userId) {
            foreach ($payload['items'] as $item) {
                PayrollSetting::set(
                    $item['key'],
                    $item['value'] ?? null,
                    $userId,
                    $item['category'] ?? 'general',
                    $item['label'] ?? null,
                );
            }
        });

        return response()->json(['data' => PayrollSetting::all()]);
    }

    /* =================== Meta (options) =================== */

    public function meta(): JsonResponse
    {
        return response()->json(['data' => [
            'triggers' => [
                ['value' => 'late_count',        'label' => 'จำนวนครั้งที่มาสาย',           'unit' => 'ครั้ง'],
                ['value' => 'late_minutes',      'label' => 'นาทีรวมที่มาสาย',              'unit' => 'นาที'],
                ['value' => 'absent_count',      'label' => 'จำนวนวันที่ขาดงาน',            'unit' => 'วัน'],
                ['value' => 'early_leave_count', 'label' => 'จำนวนครั้งที่ออกก่อนเวลา',     'unit' => 'ครั้ง'],
                ['value' => 'missing_punch',     'label' => 'จำนวนครั้งที่ลืมตอกบัตร',      'unit' => 'ครั้ง'],
                ['value' => 'no_disqualifier',   'label' => 'ไม่มีเหตุเสียสิทธิ์ (เบี้ยขยัน)', 'unit' => '-'],
                ['value' => 'rating_avg',        'label' => 'คะแนนงานเฉลี่ย',               'unit' => 'ดาว'],
                ['value' => 'tenure_years',      'label' => 'อายุงาน',                       'unit' => 'ปี'],
                ['value' => 'ot_hours',          'label' => 'ชั่วโมง OT',                    'unit' => 'ชม.'],
                ['value' => 'leave_over_quota',  'label' => 'ลาเกินสิทธิ์',                  'unit' => 'วัน'],
            ],
            'accumulation_modes' => [
                ['value' => 'repeating',      'label' => 'ทุกๆ ครบ N (สะสมซ้ำ)'],
                ['value' => 'one_shot',       'label' => 'ครบเกณฑ์ครั้งเดียว'],
                ['value' => 'tiered',         'label' => 'ขั้นบันได (กำหนดเอง)'],
                ['value' => 'per_occurrence', 'label' => 'ต่อเหตุการณ์ (×จำนวน)'],
            ],
            'amount_types' => [
                ['value' => 'fixed',           'label' => 'จำนวนคงที่'],
                ['value' => 'per_occurrence',  'label' => 'ต่อครั้ง × จำนวน'],
                ['value' => 'percent_salary',  'label' => '% ของเงินเดือน'],
                ['value' => 'daily_rate',      'label' => 'เรทรายวัน (เงินเดือน/30) × วัน'],
                ['value' => 'formula',         'label' => 'สูตรกำหนดเอง'],
            ],
            'comparisons' => [
                ['value' => '>=',    'label' => '≥ มากกว่าหรือเท่ากับ'],
                ['value' => '>',     'label' => '> มากกว่า'],
                ['value' => '=',     'label' => '= เท่ากับ'],
                ['value' => 'every', 'label' => 'ทุกๆ (สะสมครบ)'],
            ],
            'disqualifiers' => [
                ['value' => 'absent',          'label' => 'ขาดงาน'],
                ['value' => 'late',            'label' => 'มาสาย'],
                ['value' => 'early_leave',     'label' => 'ออกก่อนเวลา'],
                ['value' => 'missing_punch',   'label' => 'ลืมตอกบัตร'],
                ['value' => 'leave_sick',      'label' => 'ลาป่วย'],
                ['value' => 'leave_personal',  'label' => 'ลากิจ'],
                ['value' => 'leave_vacation',  'label' => 'ลาพักร้อน'],
                ['value' => 'leave_maternity', 'label' => 'ลาคลอด'],
                ['value' => 'leave_other',     'label' => 'ลาอื่น ๆ'],
                ['value' => 'holiday_absent',  'label' => 'ไม่มาทำงานในวันหยุด (วันหยุดบริษัท)'],
            ],
            'periods' => [
                ['value' => 'monthly', 'label' => 'รายเดือน'],
                ['value' => 'yearly',  'label' => 'รายปี'],
                ['value' => 'period',  'label' => 'ต่อรอบจ่าย'],
            ],
            'formula_variables' => [
                '{salary}'        => 'เงินเดือนฐาน',
                '{count}'         => 'จำนวนครั้ง/วัน ตาม trigger',
                '{minutes}'       => 'นาทีรวม (สำหรับ late_minutes)',
                '{days}'          => 'จำนวนวัน',
                '{rating}'        => 'คะแนนเฉลี่ย',
                '{tenure_years}'  => 'อายุงาน (ปี)',
                '{ot_hours}'      => 'ชั่วโมง OT',
            ],
        ]]);
    }

    /* =================== Validation =================== */

    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code'              => ['nullable', 'string', 'max:60', Rule::unique('payroll_rules', 'code')->ignore($id)],
            'name'              => ['required', 'string', 'max:200'],
            'type'              => ['required', 'in:deduction,bonus'],
            'trigger'           => ['required', Rule::in(self::ALLOWED_TRIGGERS)],
            'accumulation_mode' => ['required', 'in:repeating,one_shot,tiered,per_occurrence'],
            'threshold'         => ['nullable', 'integer', 'min:0'],
            'comparison'        => ['nullable', 'in:>=,>,=,every'],
            'tiers'             => ['nullable', 'array'],
            'tiers.*.threshold' => ['required_with:tiers', 'numeric', 'min:0'],
            'tiers.*.amount'    => ['required_with:tiers', 'numeric'],
            'amount_type'       => ['required', 'in:fixed,per_occurrence,percent_salary,daily_rate,formula'],
            'amount'            => ['nullable', 'numeric'],
            'formula'           => ['nullable', 'string', 'max:500'],
            'disqualifiers'     => ['nullable', 'array'],
            'disqualifiers.*'   => [Rule::in(self::ALLOWED_DISQUALIFIERS)],
            'min_per_period'    => ['nullable', 'numeric'],
            'max_per_period'    => ['nullable', 'numeric'],
            'period'            => ['required', 'in:monthly,yearly,period'],
            'priority'          => ['nullable', 'integer'],
            'active'            => ['nullable', 'boolean'],
            'effective_from'    => ['nullable', 'date'],
            'effective_to'      => ['nullable', 'date', 'after_or_equal:effective_from'],
            'note'              => ['nullable', 'string'],
        ]);
    }
}
