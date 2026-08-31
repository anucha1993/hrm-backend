$rows = App\Models\Employee::whereNotNull('labour_id')->limit(3)->get(['id','employee_code','first_name','last_name','labour_id']);
echo json_encode($rows);
