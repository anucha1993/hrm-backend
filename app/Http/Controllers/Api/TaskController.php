<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TaskItem;
use App\Models\TaskItemPhoto;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    private const RELATIONS = [
        'assignees.employee:id,employee_code,title,first_name,last_name,nickname,avatar_path,department_id',
        'assignees.rater:id,name',
        'items.completedBy:id,employee_code,first_name,last_name,nickname',
        'items.photos.uploader:id,employee_code,first_name,last_name,nickname',
        'creator:id,name',
    ];

    /**
     * รายการงาน:
     * - มีสิทธิ์ tasks.manage → เห็นทุกใบ (กรองได้)
     * - มีเพียง tasks.view → เห็นเฉพาะงานที่ตนเองถูก assign
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Task::with(self::RELATIONS)->orderByDesc('id');

        if (! $user->hasPermission('tasks.manage')) {
            $employeeId = optional($user->employee)->id;
            if (! $employeeId) {
                return response()->json(['data' => ['data' => [], 'total' => 0]]);
            }
            $q->whereHas('assignees', fn ($w) => $w->where('employee_id', $employeeId));
        }

        if ($s = $request->string('search')->toString()) {
            $q->where(function ($w) use ($s) {
                $w->where('code', 'like', "%{$s}%")
                  ->orWhere('title', 'like', "%{$s}%");
            });
        }
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }
        if ($id = $request->integer('employee_id')) {
            $q->whereHas('assignees', fn ($w) => $w->where('employee_id', $id));
        }
        if ($from = $request->string('from')->toString()) $q->whereDate('due_date', '>=', $from);
        if ($to = $request->string('to')->toString())     $q->whereDate('due_date', '<=', $to);

        return response()->json(['data' => $q->paginate($request->integer('per_page', 20))]);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $this->ensureCanView($request, $task);
        return response()->json(['data' => $task->load(self::RELATIONS)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        return DB::transaction(function () use ($data, $request) {
            $task = Task::create([
                'code'          => Task::generateCode(),
                'title'         => $data['title'],
                'description'   => $data['description'] ?? null,
                'priority'      => $data['priority'] ?? 'normal',
                'due_date'      => $data['due_date'] ?? null,
                'location_name' => $data['location_name'] ?? null,
                'note'          => $data['note'] ?? null,
                'status'        => 'open',
                'created_by'    => $request->user()?->id,
            ]);
            foreach ($data['employee_ids'] as $eid) {
                $task->assignees()->create([
                    'employee_id' => $eid,
                    'status'      => 'pending',
                ]);
            }
            foreach (array_values($data['items'] ?? []) as $i => $item) {
                $task->items()->create([
                    'title'      => $item['title'],
                    'sort_order' => $i,
                ]);
            }
            return response()->json(['data' => $task->load(self::RELATIONS)], 201);
        });
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $data = $this->validateData($request, $task->id);

        return DB::transaction(function () use ($data, $task) {
            $task->update([
                'title'         => $data['title'],
                'description'   => $data['description'] ?? null,
                'priority'      => $data['priority'] ?? 'normal',
                'due_date'      => $data['due_date'] ?? null,
                'location_name' => $data['location_name'] ?? null,
                'note'          => $data['note'] ?? null,
            ]);

            // sync assignees: ลบที่ไม่อยู่ใน list, เพิ่มที่ใหม่
            $newIds = collect($data['employee_ids'])->unique()->values();
            $existing = $task->assignees()->pluck('employee_id', 'id'); // [id => employee_id]

            // ลบ assignee ที่ไม่มี progress (pending) ที่ไม่อยู่ใน list ใหม่
            $task->assignees()
                ->where('status', 'pending')
                ->whereNotIn('employee_id', $newIds)
                ->delete();

            foreach ($newIds as $eid) {
                if (! $existing->contains($eid)) {
                    $task->assignees()->create([
                        'employee_id' => $eid,
                        'status'      => 'pending',
                    ]);
                }
            }

            // sync งานย่อย: อัปเดตที่มี id เดิม, เพิ่มใหม่ที่ไม่มี id, ลบที่ไม่อยู่ใน list แล้ว
            $incoming = array_values($data['items'] ?? []);
            $keepIds = collect($incoming)->pluck('id')->filter()->all();
            $task->items()->whereNotIn('id', $keepIds)->delete();
            foreach ($incoming as $i => $item) {
                if (!empty($item['id'])) {
                    $task->items()->where('id', $item['id'])->update([
                        'title'      => $item['title'],
                        'sort_order' => $i,
                    ]);
                } else {
                    $task->items()->create([
                        'title'      => $item['title'],
                        'sort_order' => $i,
                    ]);
                }
            }

            $task->refreshStatusFromAssignees();
            return response()->json(['data' => $task->load(self::RELATIONS)]);
        });
    }

    public function destroy(Task $task): JsonResponse
    {
        $task->delete();
        return response()->json(['message' => 'ลบเรียบร้อย']);
    }

    /**
     * อัปโหลดรูป before/after (เฉพาะผู้รับงานเท่านั้น)
     */
    public function uploadPhoto(Request $request, Task $task, TaskAssignee $assignee): JsonResponse
    {
        if ($assignee->task_id !== $task->id) {
            abort(404);
        }

        $user = $request->user();
        $employeeId = optional($user->employee)->id;
        $isOwner = $employeeId && $assignee->employee_id === $employeeId;
        $isManager = $user->hasPermission('tasks.manage');
        if (! $isOwner && ! $isManager) {
            abort(403, 'ไม่มีสิทธิ์อัปโหลดรูปงานนี้');
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(['before', 'after'])],
            'photo' => ['required', 'image', 'max:8192'], // 8MB
        ]);

        $path = $data['photo']->store("tasks/{$task->id}", 'public');
        $field = $data['kind'] === 'before' ? 'before_photo_path' : 'after_photo_path';

        // ลบรูปเก่า
        if ($assignee->{$field}) {
            Storage::disk('public')->delete($assignee->{$field});
        }
        $assignee->{$field} = $path;

        // อัปเดต status: ถ้ายังเป็น pending และอัปรูป before → in_progress
        if ($data['kind'] === 'before' && $assignee->status === 'pending') {
            $assignee->status = 'in_progress';
            $assignee->started_at = now();
        }
        $assignee->save();
        $task->refreshStatusFromAssignees();

        return response()->json(['data' => $assignee->fresh()->load('employee')]);
    }

    /**
     * อัปโหลดรูป before/after ของ "รายการงานย่อย" — อัปได้หลายรูปต่อครั้ง (ผู้รับงาน หรือ admin)
     */
    public function uploadItemPhoto(Request $request, Task $task, TaskItem $item): JsonResponse
    {
        if ($item->task_id !== $task->id) abort(404);

        $user = $request->user();
        $employeeId = optional($user->employee)->id;
        $isAssignee = $employeeId && $task->assignees()->where('employee_id', $employeeId)->exists();
        $isManager = $user->hasPermission('tasks.manage');
        if (! $isAssignee && ! $isManager) {
            abort(403, 'ไม่มีสิทธิ์อัปโหลดรูปงานย่อยนี้');
        }

        $data = $request->validate([
            'kind'     => ['required', Rule::in(['before', 'after'])],
            'photos'   => ['required', 'array', 'min:1'],
            'photos.*' => ['image', 'max:8192'], // 8MB ต่อรูป
        ]);

        $created = [];
        foreach ($data['photos'] as $file) {
            $path = $file->store("tasks/{$task->id}/items/{$item->id}", 'public');
            $created[] = $item->photos()->create([
                'kind'        => $data['kind'],
                'photo_path'  => $path,
                'employee_id' => $employeeId,
            ]);
        }

        // อัปรูป before ครั้งแรก → ถือว่าเริ่มงานแล้ว
        if ($data['kind'] === 'before' && $isAssignee) {
            $assignee = $task->assignees()->where('employee_id', $employeeId)->first();
            if ($assignee && $assignee->status === 'pending') {
                $assignee->status = 'in_progress';
                $assignee->started_at = now();
                $assignee->save();
                $task->refreshStatusFromAssignees();
            }
        }

        return response()->json(['data' => $item->fresh()->load('photos.uploader')], 201);
    }

    /**
     * ลบรูปงานย่อย (เฉพาะคนอัปโหลดเอง หรือ admin)
     */
    public function deleteItemPhoto(Request $request, Task $task, TaskItem $item, TaskItemPhoto $photo): JsonResponse
    {
        if ($item->task_id !== $task->id || $photo->task_item_id !== $item->id) abort(404);

        $user = $request->user();
        $employeeId = optional($user->employee)->id;
        $isOwner = $employeeId && $photo->employee_id === $employeeId;
        $isManager = $user->hasPermission('tasks.manage');
        if (! $isOwner && ! $isManager) {
            abort(403, 'ไม่มีสิทธิ์ลบรูปนี้');
        }

        Storage::disk('public')->delete($photo->photo_path);
        $photo->delete();

        return response()->json(['message' => 'ลบรูปเรียบร้อย']);
    }

    /**
     * ส่งงาน (ต้องมีทั้ง before + after)
     */
    public function submit(Request $request, Task $task, TaskAssignee $assignee): JsonResponse
    {
        if ($assignee->task_id !== $task->id) abort(404);

        $user = $request->user();
        $employeeId = optional($user->employee)->id;
        if (! $employeeId || $assignee->employee_id !== $employeeId) {
            if (! $user->hasPermission('tasks.manage')) {
                abort(403, 'ไม่มีสิทธิ์ส่งงานนี้');
            }
        }

        $items = $task->items;
        if ($items->isNotEmpty()) {
            $missing = $items->filter(function (TaskItem $it) {
                return $it->photos()->where('kind', 'before')->doesntExist()
                    || $it->photos()->where('kind', 'after')->doesntExist();
            });
            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'photo' => 'ทุกรายการงานย่อยต้องมีรูปก่อนทำและหลังทำอย่างน้อยอย่างละ 1 รูป ก่อนส่งงาน: '
                        . $missing->pluck('title')->implode(', '),
                ]);
            }
        } elseif (! $assignee->before_photo_path || ! $assignee->after_photo_path) {
            throw ValidationException::withMessages([
                'photo' => 'ต้องอัปโหลดทั้งภาพก่อนทำ (before) และภาพหลังทำ (after) ก่อนส่งงาน',
            ]);
        }

        $data = $request->validate([
            'submit_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $assignee->status = 'submitted';
        $assignee->submitted_at = now();
        $assignee->submit_note = $data['submit_note'] ?? null;
        $assignee->save();
        $task->refreshStatusFromAssignees();

        return response()->json(['data' => $assignee->fresh()->load('employee')]);
    }

    /**
     * Admin ให้คะแนนผู้ส่งงาน (1-5 ดาว)
     */
    public function rate(Request $request, Task $task, TaskAssignee $assignee): JsonResponse
    {
        if ($assignee->task_id !== $task->id) abort(404);

        $data = $request->validate([
            'rating'      => ['required', 'integer', 'between:1,5'],
            'rating_note' => ['nullable', 'string', 'max:500'],
            'approve'     => ['sometimes', 'boolean'],
        ]);

        $assignee->rating = $data['rating'];
        $assignee->rating_note = $data['rating_note'] ?? null;
        $assignee->rated_at = now();
        $assignee->rated_by = $request->user()?->id;
        if ($data['approve'] ?? true) {
            $assignee->status = 'approved';
        }
        $assignee->save();
        $task->refreshStatusFromAssignees();

        return response()->json(['data' => $assignee->fresh()->load(['employee', 'rater'])]);
    }

    /**
     * Admin ปฏิเสธงาน (ส่งกลับ in_progress)
     */
    public function reject(Request $request, Task $task, TaskAssignee $assignee): JsonResponse
    {
        if ($assignee->task_id !== $task->id) abort(404);

        $data = $request->validate([
            'rating_note' => ['nullable', 'string', 'max:500'],
        ]);

        $assignee->status = 'rejected';
        $assignee->rating_note = $data['rating_note'] ?? null;
        $assignee->save();
        $task->refreshStatusFromAssignees();

        return response()->json(['data' => $assignee->fresh()->load('employee')]);
    }

    /**
     * สรุปงาน
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $base = Task::query();
        if (! $user->hasPermission('tasks.manage')) {
            $employeeId = optional($user->employee)->id;
            if (! $employeeId) {
                return response()->json(['data' => ['totals' => [], 'by_status' => [], 'by_employee' => []]]);
            }
            $base->whereHas('assignees', fn ($w) => $w->where('employee_id', $employeeId));
        }

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')->pluck('cnt', 'status');

        $byEmployee = TaskAssignee::query()
            ->selectRaw('employee_id, COUNT(*) as total_assigned, SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved, AVG(rating) as avg_rating')
            ->with('employee:id,employee_code,first_name,last_name,nickname')
            ->groupBy('employee_id')
            ->orderByDesc('total_assigned')
            ->get();

        $totals = [
            'total_tasks'   => (clone $base)->count(),
            'open'          => (int) ($byStatus['open'] ?? 0),
            'in_progress'   => (int) ($byStatus['in_progress'] ?? 0),
            'submitted'     => (int) ($byStatus['submitted'] ?? 0),
            'completed'     => (int) ($byStatus['completed'] ?? 0),
            'cancelled'     => (int) ($byStatus['cancelled'] ?? 0),
        ];

        return response()->json(['data' => [
            'totals'      => $totals,
            'by_status'   => $byStatus,
            'by_employee' => $byEmployee,
        ]]);
    }

    /**
     * เช็ค/ยกเลิกเช็ค งานย่อย (ผู้รับงานคนใดคนหนึ่ง หรือ admin)
     */
    public function toggleItem(Request $request, Task $task, TaskItem $item): JsonResponse
    {
        if ($item->task_id !== $task->id) abort(404);

        $user = $request->user();
        $employeeId = optional($user->employee)->id;
        $isAssignee = $employeeId && $task->assignees()->where('employee_id', $employeeId)->exists();
        $isManager = $user->hasPermission('tasks.manage');
        if (! $isAssignee && ! $isManager) {
            abort(403, 'ไม่มีสิทธิ์แก้ไขงานย่อยนี้');
        }

        if ($item->is_completed) {
            $item->is_completed = false;
            $item->completed_at = null;
            $item->completed_by = null;
        } else {
            $item->is_completed = true;
            $item->completed_at = now();
            $item->completed_by = $employeeId;
        }
        $item->save();

        return response()->json(['data' => $item->fresh()->load('completedBy')]);
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'title'         => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'priority'      => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_date'      => ['nullable', 'date'],
            'location_name' => ['nullable', 'string', 'max:200'],
            'note'          => ['nullable', 'string'],
            'employee_ids'   => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'items'           => ['nullable', 'array'],
            'items.*.id'      => ['nullable', 'integer'],
            'items.*.title'   => ['required', 'string', 'max:255'],
        ]);
    }

    private function ensureCanView(Request $request, Task $task): void
    {
        $user = $request->user();
        if ($user->hasPermission('tasks.manage')) return;

        $employeeId = optional($user->employee)->id;
        $assigned = $employeeId && $task->assignees()->where('employee_id', $employeeId)->exists();
        abort_unless($assigned, 403, 'ไม่มีสิทธิ์ดูงานนี้');
    }
}
