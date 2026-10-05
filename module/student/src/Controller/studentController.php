<?php

namespace Student\Controller;

session_start();

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Paginator\Paginator;
use Laminas\Paginator\Adapter\DbSelect;
use Laminas\Mvc\MvcEvent;

class StudentController extends AbstractActionController
{
    private TableGateway $tableGateway;
private TableGateway $userTableGateway;

public function __construct(
    TableGateway $tableGateway,
    TableGateway $userTableGateway
) {
    $this->tableGateway = $tableGateway;
    $this->userTableGateway = $userTableGateway;
}
   public function onDispatch(MvcEvent $e)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $action = $this->params()->fromRoute('action', 'index');

    // Check whether the user is logged in
 if (
    !in_array(
        $action,
        ['login', 'signup', 'forgot-password'],
        true
    )
    && empty($_SESSION['logged_in'])
) {
    return $this->redirect()->toRoute('student', [
        'action' => 'login'
    ]);
}

    // Admin-only actions
    $adminActions = [
    'users',
    'add-user',
    'edit-user',
    'delete-user',
    'toggle-user-status',
    'activity-logs',
    'export-activity-logs',
    'export'
];

    // Block normal users from admin pages
    if (
        in_array($action, $adminActions, true) &&
        ($_SESSION['role'] ?? '') !== 'admin'
    ) {
        return $this->redirect()->toRoute('student', [
            'action' => 'dashboard'
        ]);
    }

    return parent::onDispatch($e);
}
public function activityLogsAction()
{
    // Only admin can view activity logs
    if (($_SESSION['role'] ?? '') !== 'admin') {

        return $this->redirect()->toRoute('student', [
            'action' => 'dashboard'
        ]);
    }

    $search = trim(
        $this->params()->fromQuery('search', '')
    );

    $fromDate = trim(
        $this->params()->fromQuery('from_date', '')
    );

    $toDate = trim(
        $this->params()->fromQuery('to_date', '')
    );

    $page = max(
        1,
        (int) $this->params()->fromQuery('page', 1)
    );

    $perPage = 10;

    $sql = new \Laminas\Db\Sql\Sql(
        $this->userTableGateway->getAdapter()
    );

    $select = $sql->select('activity_logs');

    // Search username or action
    if ($search !== '') {

        $select->where->nest()
            ->like('username', '%' . $search . '%')
            ->or
            ->like('action', '%' . $search . '%')
            ->unnest();
    }

    // From date
    if ($fromDate !== '') {

        $select->where
            ->greaterThanOrEqualTo(
                'created_at',
                $fromDate . ' 00:00:00'
            );
    }

    // To date
    if ($toDate !== '') {

        $select->where
            ->lessThanOrEqualTo(
                'created_at',
                $toDate . ' 23:59:59'
            );
    }
    $activityType = trim(
    $this->params()->fromQuery('activity_type', '')
);

    $select->order('id DESC');
    // Activity type filter
if ($activityType !== '') {

    switch ($activityType) {

        case 'login':
            $select->where
                ->like('action', 'Logged in%');
            break;

        case 'logout':
            $select->where
                ->like('action', 'Logged out%');
            break;

        case 'student':
            $select->where
                ->like('action', '%student%');
            break;

        case 'user':
            $select->where
                ->like('action', '%user%');
            break;

        case 'password':
            $select->where
                ->like('action', '%password%');
            break;
    }
}

    // Get total number of matching logs
    $countSelect = clone $select;

    $countSelect->reset(
        \Laminas\Db\Sql\Select::COLUMNS
    );

    $countSelect->columns([
        'count' => new \Laminas\Db\Sql\Expression('COUNT(*)')
    ]);

    $countStatement =
        $sql->prepareStatementForSqlObject($countSelect);

    $countResult = $countStatement->execute()->current();

    $totalLogs = (int) ($countResult['count'] ?? 0);

    // Calculate pagination
    $totalPages = max(
        1,
        (int) ceil($totalLogs / $perPage)
    );

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;

    $select->limit($perPage);
    $select->offset($offset);

    $statement =
        $sql->prepareStatementForSqlObject($select);

    $result = $statement->execute();

    $logs = [];

    foreach ($result as $row) {
        $logs[] = $row;
    }

    return new ViewModel([
        'logs'       => $logs,
        'search'     => $search,
        'fromDate'   => $fromDate,
        'toDate'     => $toDate,
        'activityType' => $activityType,
        'page'       => $page,
        'totalPages' => $totalPages,
        'totalLogs'  => $totalLogs
    ]);
}
private function logActivity(string $action): void
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $username = $_SESSION['username'] ?? '';

    if ($userId <= 0 || $username === '') {
        return;
    }

    $sql = new \Laminas\Db\Sql\Sql(
        $this->userTableGateway->getAdapter()
    );

    $insert = $sql->insert('activity_logs');

    $insert->values([
        'user_id' => $userId,
        'username' => $username,
        'action' => $action,
    ]);

    $sql->prepareStatementForSqlObject($insert)->execute();
}

    // Student List
public function indexAction()
{
    // ========================================
    // EXISTING SEARCH / FILTERS
    // ========================================

    $search = trim(
        $this->params()->fromQuery('search', '')
    );

    $course = trim(
        $this->params()->fromQuery('course', '')
    );

    $gender = trim(
        $this->params()->fromQuery('gender', '')
    );


    // ========================================
    // NEW ADMISSION FILTERS
    // ========================================

    $admissionNumber = trim(
        $this->params()->fromQuery(
            'admission_number',
            ''
        )
    );

    $batch = trim(
        $this->params()->fromQuery(
            'batch',
            ''
        )
    );

    $admissionStatus = trim(
        $this->params()->fromQuery(
            'admission_status',
            ''
        )
    );

    $paymentStatus = trim(
        $this->params()->fromQuery(
            'payment_status',
            ''
        )
    );


    // ========================================
    // SORTING
    // ========================================

    $sort = $this->params()->fromQuery(
        'sort',
        'id'
    );

    $order = $this->params()->fromQuery(
        'order',
        'ASC'
    );


    // ========================================
    // PAGE
    // ========================================

    $page = (int) $this->params()->fromQuery(
        'page',
        1
    );


    // ========================================
    // SELECT
    // ========================================

    $select = $this->tableGateway
        ->getSql()
        ->select();


    $where = new \Laminas\Db\Sql\Where();


    // ========================================
    // GENERAL SEARCH
    // Name / Email / Course / Admission Number
    // ========================================

    if ($search !== '') {

        $searchWhere =
            new \Laminas\Db\Sql\Where();

        $searchWhere
            ->like(
                'name',
                '%' . $search . '%'
            )
            ->or
            ->like(
                'email',
                '%' . $search . '%'
            )
            ->or
            ->like(
                'course',
                '%' . $search . '%'
            )
            ->or
            ->like(
                'admission_number',
                '%' . $search . '%'
            );

        $where->addPredicate(
            $searchWhere
        );
    }


    // ========================================
    // COURSE FILTER
    // ========================================

    if ($course !== '') {

        $where->equalTo(
            'course',
            $course
        );
    }


    // ========================================
    // GENDER FILTER
    // ========================================

    if ($gender !== '') {

        $where->equalTo(
            'gender',
            $gender
        );
    }


    // ========================================
    // ADMISSION NUMBER FILTER
    // ========================================

    if ($admissionNumber !== '') {

        $where->like(
            'admission_number',
            '%' . $admissionNumber . '%'
        );
    }


    // ========================================
    // BATCH FILTER
    // ========================================

    if ($batch !== '') {

        $where->like(
            'batch',
            '%' . $batch . '%'
        );
    }


    // ========================================
    // ADMISSION STATUS FILTER
    // ========================================

    if ($admissionStatus !== '') {

        $where->equalTo(
            'admission_status',
            $admissionStatus
        );
    }


    // ========================================
    // PAYMENT STATUS FILTER
    // ========================================

    if ($paymentStatus !== '') {

        $where->equalTo(
            'payment_status',
            $paymentStatus
        );
    }


    // ========================================
    // APPLY WHERE
    // ========================================

    $select->where($where);


    // ========================================
    // ALLOWED SORT COLUMNS
    // ========================================

    $allowedSorts = [

        'id' =>
            'id',

        'name' =>
            'name',

        'course' =>
            'course',

        'admission_number' =>
            'admission_number',

        'admission_date' =>
            'admission_date',

        'admission_status' =>
            'admission_status',

        'payment_status' =>
            'payment_status',

        'created_at' =>
            'created_at'
    ];


    $sortColumn =
        $allowedSorts[$sort] ?? 'id';


    // ========================================
    // SORT ORDER VALIDATION
    // ========================================

    $order = strtoupper($order);

    if (
        !in_array(
            $order,
            ['ASC', 'DESC'],
            true
        )
    ) {

        $order = 'ASC';
    }


    $select->order(
        $sortColumn . ' ' . $order
    );


    // ========================================
    // PAGINATION
    // ========================================

    $adapter =
        new \Laminas\Paginator\Adapter\DbSelect(
            $select,
            $this->tableGateway->getAdapter()
        );


    $paginator =
        new \Laminas\Paginator\Paginator(
            $adapter
        );


    $paginator->setCurrentPageNumber(
        $page
    );

    $paginator->setItemCountPerPage(
        10
    );


    // ========================================
    // SEND DATA TO VIEW
    // ========================================

    return new ViewModel([

        'students' =>
            $paginator,

        'search' =>
            $search,

        'course' =>
            $course,

        'gender' =>
            $gender,

        'admission_number' =>
            $admissionNumber,

        'batch' =>
            $batch,

        'admission_status' =>
            $admissionStatus,

        'payment_status' =>
            $paymentStatus,

        'sort' =>
            $sort,

        'order' =>
            $order
    ]);
}
/**
 * Export Students to CSV
 */
public function exportAction()
{
    // Admin only
    if (($_SESSION['role'] ?? '') !== 'admin') {
        return $this->redirect()->toRoute('student', [
            'action' => 'dashboard'
        ]);
    }

    // Get filters
    $search = trim(
        $this->params()->fromQuery('search', '')
    );

    $course = trim(
        $this->params()->fromQuery('course', '')
    );

    $gender = trim(
        $this->params()->fromQuery('gender', '')
    );

    $admissionNumber = trim(
        $this->params()->fromQuery('admission_number', '')
    );

    $batch = trim(
        $this->params()->fromQuery('batch', '')
    );

    $admissionStatus = trim(
        $this->params()->fromQuery('admission_status', '')
    );

    $paymentStatus = trim(
        $this->params()->fromQuery('payment_status', '')
    );

    $sort = $this->params()->fromQuery(
        'sort',
        'id'
    );

    $order = strtolower(
        $this->params()->fromQuery(
            'order',
            'asc'
        )
    );

    // Allowed sorting columns
    $allowedSorts = [
        'id' => 'id',
        'name' => 'name',
        'course' => 'course',
        'admission_number' => 'admission_number',
        'admission_date' => 'admission_date',
        'admission_status' => 'admission_status',
        'payment_status' => 'payment_status',
        'created_at' => 'created_at'
    ];

    $sortColumn = $allowedSorts[$sort] ?? 'id';

    $order = ($order === 'desc')
        ? 'DESC'
        : 'ASC';

    // Create SELECT
    $sql = new \Laminas\Db\Sql\Sql(
        $this->tableGateway->getAdapter()
    );

    $select = $sql->select('studentsinfo');

    // Search
    if ($search !== '') {

        $searchValue = '%' . $search . '%';

        $select->where
            ->nest()
            ->like('name', $searchValue)
            ->or
            ->like('email', $searchValue)
            ->or
            ->like('course', $searchValue)
            ->or
            ->like('admission_number', $searchValue)
            ->unnest();
    }

    // Course
    if ($course !== '') {
        $select->where([
            'course' => $course
        ]);
    }

    // Gender
    if ($gender !== '') {
        $select->where([
            'gender' => $gender
        ]);
    }

    // Admission Number
    if ($admissionNumber !== '') {
        $select->where([
            'admission_number' => $admissionNumber
        ]);
    }

    // Batch
    if ($batch !== '') {
        $select->where([
            'batch' => $batch
        ]);
    }

    // Admission Status
    if ($admissionStatus !== '') {
        $select->where([
            'admission_status' => $admissionStatus
        ]);
    }

    // Payment Status
    if ($paymentStatus !== '') {
        $select->where([
            'payment_status' => $paymentStatus
        ]);
    }

    // Sorting
    $select->order(
        $sortColumn . ' ' . $order
    );

    // Execute query
    $statement = $sql->prepareStatementForSqlObject(
        $select
    );

    $result = $statement->execute();

    // Create CSV
    $csv = '';

    // Header row
    $headers = [
        'ID',
        'Admission Number',
        'Student Name',
        'Date of Birth',
        'Email',
        'Phone',
        'Alternate Phone',
        'Course',
        'Gender',
        'Address',
        'City',
        'State',
        'Pincode',
        'Admission Date',
        'Batch',
        'Previous Qualification',
        'Previous Institution',
        'Parent Name',
        'Parent Phone',
        'Admission Status',
        'Total Fee',
        'Amount Paid',
        'Payment Status',
        'Latitude',
        'Longitude'
    ];

    $csv .= $this->csvRow($headers);

    // Student rows
    foreach ($result as $student) {

        $csv .= $this->csvRow([
            $student['id'] ?? '',
            $student['admission_number'] ?? '',
            $student['name'] ?? '',
            $student['date_of_birth'] ?? '',
            $student['email'] ?? '',
            $student['phone'] ?? '',
            $student['alternate_phone'] ?? '',
            $student['course'] ?? '',
            $student['gender'] ?? '',
            $student['address'] ?? '',
            $student['city'] ?? '',
            $student['state'] ?? '',
            $student['pincode'] ?? '',
            $student['admission_date'] ?? '',
            $student['batch'] ?? '',
            $student['previous_qualification'] ?? '',
            $student['previous_institution'] ?? '',
            $student['parent_name'] ?? '',
            $student['parent_phone'] ?? '',
            $student['admission_status'] ?? '',
            $student['total_fee'] ?? '0',
            $student['amount_paid'] ?? '0',
            $student['payment_status'] ?? '',
            $student['latitude'] ?? '',
            $student['longitude'] ?? ''
        ]);
    }

    // Send CSV response
    $response = $this->getResponse();

    $response->getHeaders()
        ->addHeaderLine(
            'Content-Type',
            'text/csv; charset=utf-8'
        )
        ->addHeaderLine(
            'Content-Disposition',
            'attachment; filename="students.csv"'
        )
        ->addHeaderLine(
            'Cache-Control',
            'no-cache, no-store, must-revalidate'
        );

    $response->setContent($csv);

    return $response;
}
/**
 * Convert an array into a CSV row
 */
private function csvRow(array $data): string
{
    $handle = fopen('php://memory', 'r+');

    fputcsv($handle, $data);

    rewind($handle);

    $row = stream_get_contents($handle);

    fclose($handle);

    return $row;
}
public function exportActivityLogsAction()
{
    // Only admin can export activity logs
    if (($_SESSION['role'] ?? '') !== 'admin') {
        return $this->redirect()->toRoute('student', [
            'action' => 'dashboard'
        ]);
    }

    $search = trim(
        $this->params()->fromQuery('search', '')
    );

    $fromDate = trim(
        $this->params()->fromQuery('from_date', '')
    );

    $toDate = trim(
        $this->params()->fromQuery('to_date', '')
    );

    $activityType = trim(
        $this->params()->fromQuery('activity_type', '')
    );

    $sql = new \Laminas\Db\Sql\Sql(
        $this->userTableGateway->getAdapter()
    );

    $select = $sql->select('activity_logs');

    // Search username or action
    if ($search !== '') {
        $select->where->nest()
            ->like('username', '%' . $search . '%')
            ->or
            ->like('action', '%' . $search . '%')
            ->unnest();
    }

    // From date
    if ($fromDate !== '') {
        $select->where->greaterThanOrEqualTo(
            'created_at',
            $fromDate . ' 00:00:00'
        );
    }

    // To date
    if ($toDate !== '') {
        $select->where->lessThanOrEqualTo(
            'created_at',
            $toDate . ' 23:59:59'
        );
    }

    // Activity type
    if ($activityType !== '') {

        switch ($activityType) {

            case 'login':
                $select->where->like(
                    'action',
                    'Logged in%'
                );
                break;

            case 'logout':
                $select->where->like(
                    'action',
                    'Logged out%'
                );
                break;

            case 'student':
                $select->where->like(
                    'action',
                    '%student%'
                );
                break;

            case 'user':
                $select->where->like(
                    'action',
                    '%user%'
                );
                break;

            case 'password':
                $select->where->like(
                    'action',
                    '%password%'
                );
                break;
        }
    }

    $select->order('id DESC');

    $statement =
        $sql->prepareStatementForSqlObject($select);

    $result = $statement->execute();

    // CSV headers
    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="activity_logs.csv"'
    );

    $output = fopen('php://output', 'w');

    // CSV column headings
    fputcsv($output, [
        'ID',
        'User ID',
        'Username',
        'Action',
        'Date & Time'
    ]);

    foreach ($result as $row) {

        fputcsv($output, [
            $row['id'],
            $row['user_id'],
            $row['username'],
            $row['action'],
            $row['created_at']
        ]);
    }

    fclose($output);

    exit;
}
//add student
 public function addAction()
{
    if (($_SESSION['role'] ?? '') !== 'admin') {

        return $this->redirect()->toRoute('student', [
            'action' => 'dashboard'
        ]);
    }

    $errors = [];
    $data = [];

    if ($this->getRequest()->isPost()) {

        $data = $this->params()->fromPost();

        // ========================================
        // STUDENT DETAILS
        // ========================================

        $admissionNumber = trim(
            $data['admission_number'] ?? ''
        );

        $name = trim(
            $data['name'] ?? ''
        );

        $dateOfBirth =
            trim($data['date_of_birth'] ?? '');

        $gender =
            trim($data['gender'] ?? '');

        $email =
            trim($data['email'] ?? '');

        $phone =
            trim($data['phone'] ?? '');

        $alternatePhone =
            trim($data['alternate_phone'] ?? '');

        // ========================================
        // EDUCATION DETAILS
        // ========================================

        $course =
            trim($data['course'] ?? '');

        $batch =
            trim($data['batch'] ?? '');

        $previousQualification =
            trim($data['previous_qualification'] ?? '');

        $previousInstitution =
            trim($data['previous_institution'] ?? '');

        // ========================================
        // PARENT DETAILS
        // ========================================

        $parentName =
            trim($data['parent_name'] ?? '');

        $parentPhone =
            trim($data['parent_phone'] ?? '');

        // ========================================
        // ADDRESS DETAILS
        // ========================================

        $address =
            trim($data['address'] ?? '');

        $city =
            trim($data['city'] ?? '');

        $state =
            trim($data['state'] ?? '');

        $pincode =
            trim($data['pincode'] ?? '');

        $latitude =
            trim($data['latitude'] ?? '');

        $longitude =
            trim($data['longitude'] ?? '');

        // ========================================
        // ADMISSION DETAILS
        // ========================================

        $admissionDate =
            trim($data['admission_date'] ?? '');

        $admissionStatus =
            trim($data['admission_status'] ?? 'Active');

        // ========================================
        // FEE DETAILS
        // ========================================

        $totalFee =
            (float) ($data['total_fee'] ?? 0);

        $amountPaid =
            (float) ($data['amount_paid'] ?? 0);

        $paymentStatus =
            trim($data['payment_status'] ?? 'Pending');


        // ========================================
        // VALIDATION
        // ========================================

        if ($admissionNumber === '') {
            $errors[] =
                'Admission number is required.';
        }

        if ($name === '') {
            $errors[] =
                'Student name is required.';
        }

        if ($course === '') {
            $errors[] =
                'Course is required.';
        }

        if ($email !== '' &&
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $errors[] =
                'Please enter a valid email address.';
        }

        if ($totalFee < 0) {
            $errors[] =
                'Total fee cannot be negative.';
        }

        if ($amountPaid < 0) {
            $errors[] =
                'Amount paid cannot be negative.';
        }

        if ($amountPaid > $totalFee) {
            $errors[] =
                'Amount paid cannot be greater than total fee.';
        }


        // ========================================
        // CHECK DUPLICATE ADMISSION NUMBER
        // ========================================

        if ($admissionNumber !== '') {

            $existingAdmission =
                $this->tableGateway
                    ->select([
                        'admission_number' =>
                            $admissionNumber
                    ])
                    ->current();

            if ($existingAdmission) {

                $errors[] =
                    'This admission number already exists.';
            }
        }


        // ========================================
        // PAYMENT STATUS
        // ========================================

        if ($amountPaid <= 0) {

            $paymentStatus = 'Pending';

        } elseif ($amountPaid < $totalFee) {

            $paymentStatus = 'Partial';

        } else {

            $paymentStatus = 'Paid';
        }


        // ========================================
        // INSERT STUDENT
        // ========================================

        if (empty($errors)) {

            $this->tableGateway->insert([

                'admission_number' =>
                    $admissionNumber,

                'name' =>
                    $name,

                'date_of_birth' =>
                    ($dateOfBirth !== '')
                        ? $dateOfBirth
                        : null,

                'email' =>
                    ($email !== '')
                        ? $email
                        : null,

                'phone' =>
                    ($phone !== '')
                        ? $phone
                        : null,

                'alternate_phone' =>
                    ($alternatePhone !== '')
                        ? $alternatePhone
                        : null,

                'course' =>
                    $course,

                'gender' =>
                    ($gender !== '')
                        ? $gender
                        : null,

                'address' =>
                    ($address !== '')
                        ? $address
                        : null,

                'city' =>
                    ($city !== '')
                        ? $city
                        : null,

                'state' =>
                    ($state !== '')
                        ? $state
                        : null,

                'pincode' =>
                    ($pincode !== '')
                        ? $pincode
                        : null,

                'latitude' =>
                    ($latitude !== '')
                        ? $latitude
                        : null,

                'longitude' =>
                    ($longitude !== '')
                        ? $longitude
                        : null,

                'admission_date' =>
                    ($admissionDate !== '')
                        ? $admissionDate
                        : null,

                'batch' =>
                    ($batch !== '')
                        ? $batch
                        : null,

                'previous_qualification' =>
                    ($previousQualification !== '')
                        ? $previousQualification
                        : null,

                'previous_institution' =>
                    ($previousInstitution !== '')
                        ? $previousInstitution
                        : null,

                'parent_name' =>
                    ($parentName !== '')
                        ? $parentName
                        : null,

                'parent_phone' =>
                    ($parentPhone !== '')
                        ? $parentPhone
                        : null,

                'admission_status' =>
                    $admissionStatus,

                'total_fee' =>
                    $totalFee,

                'amount_paid' =>
                    $amountPaid,

                'payment_status' =>
                    $paymentStatus
            ]);


            // ========================================
            // ACTIVITY LOG
            // ========================================

            $this->logActivity(
                'Added a new student - Admission No: '
                . $admissionNumber
            );


            // ========================================
            // SUCCESS MESSAGE
            // ========================================

            $this->flashMessenger()
                ->addSuccessMessage(
                    'Student admission added successfully!'
                );


            return $this->redirect()->toRoute(
                'student'
            );
        }
    }


    return new ViewModel([
        'errors' => $errors,
        'data' => $data
    ]);
}
    public function viewAction()
{
    $id = (int) $this->params()->fromRoute('id', 0);

    if ($id === 0) {
        return $this->redirect()->toRoute('student');
    }

    $student = $this->tableGateway
        ->select(['id' => $id])
        ->current();

    if (!$student) {
        return $this->redirect()->toRoute('student');
    }

    return new ViewModel([
        'student' => $student
    ]);
}

   //edit student
  public function editAction()
{
    // ========================================
    // ADMIN PERMISSION
    // ========================================

    if (($_SESSION['role'] ?? '') !== 'admin') {

        $this->flashMessenger()->addErrorMessage(
            'Only administrators can edit students.'
        );

        return $this->redirect()->toRoute('student', [
            'action' => 'index'
        ]);
    }


    // ========================================
    // GET STUDENT ID
    // ========================================

    $id = (int) $this->params()->fromRoute('id', 0);

    if ($id === 0) {

        return $this->redirect()->toRoute('student');
    }


    // ========================================
    // GET STUDENT
    // ========================================

    $student = $this->tableGateway
        ->select(['id' => $id])
        ->current();

    if (!$student) {

        return $this->redirect()->toRoute('student');
    }


    // ========================================
    // UPDATE STUDENT
    // ========================================

    if ($this->getRequest()->isPost()) {

        $data = $this->params()->fromPost();


        // ========================================
        // BASIC STUDENT DETAILS
        // ========================================

        $admissionNumber =
            trim($data['admission_number'] ?? '');

        $name =
            trim($data['name'] ?? '');

        $dateOfBirth =
            trim($data['date_of_birth'] ?? '');

        $email =
            trim($data['email'] ?? '');

        $phone =
            trim($data['phone'] ?? '');

        $alternatePhone =
            trim($data['alternate_phone'] ?? '');

        $gender =
            trim($data['gender'] ?? '');


        // ========================================
        // EDUCATION DETAILS
        // ========================================

        $course =
            trim($data['course'] ?? '');

        $batch =
            trim($data['batch'] ?? '');

        $previousQualification =
            trim($data['previous_qualification'] ?? '');

        $previousInstitution =
            trim($data['previous_institution'] ?? '');


        // ========================================
        // PARENT / GUARDIAN DETAILS
        // ========================================

        $parentName =
            trim($data['parent_name'] ?? '');

        $parentPhone =
            trim($data['parent_phone'] ?? '');


        // ========================================
        // ADDRESS DETAILS
        // ========================================

        $address =
            trim($data['address'] ?? '');

        $city =
            trim($data['city'] ?? '');

        $state =
            trim($data['state'] ?? '');

        $pincode =
            trim($data['pincode'] ?? '');

        $latitude =
            trim($data['latitude'] ?? '');

        $longitude =
            trim($data['longitude'] ?? '');


        // ========================================
        // ADMISSION DETAILS
        // ========================================

        $admissionDate =
            trim($data['admission_date'] ?? '');

        $admissionStatus =
            trim(
                $data['admission_status']
                ?? 'Active'
            );


        // ========================================
        // FEE DETAILS
        // ========================================

        $totalFee =
            (float) ($data['total_fee'] ?? 0);

        $amountPaid =
            (float) ($data['amount_paid'] ?? 0);


        // ========================================
        // VALIDATION
        // ========================================

        $errors = [];


        if ($admissionNumber === '') {

            $errors[] =
                'Admission number is required.';
        }


        if ($name === '') {

            $errors[] =
                'Student name is required.';
        }


        if ($course === '') {

            $errors[] =
                'Course is required.';
        }


        if (
            $email !== '' &&
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $errors[] =
                'Please enter a valid email address.';
        }


        if ($totalFee < 0) {

            $errors[] =
                'Total fee cannot be negative.';
        }


        if ($amountPaid < 0) {

            $errors[] =
                'Amount paid cannot be negative.';
        }


        if ($amountPaid > $totalFee) {

            $errors[] =
                'Amount paid cannot be greater than total fee.';
        }


        // ========================================
        // CHECK DUPLICATE ADMISSION NUMBER
        // ========================================

        if ($admissionNumber !== '') {

            $existingAdmission =
                $this->tableGateway
                    ->select([
                        'admission_number' =>
                            $admissionNumber
                    ])
                    ->current();

            if (
                $existingAdmission &&
                (int) $existingAdmission['id'] !== $id
            ) {

                $errors[] =
                    'This admission number already exists.';
            }
        }


        // ========================================
        // PAYMENT STATUS
        // ========================================

        if ($amountPaid <= 0) {

            $paymentStatus = 'Pending';

        } elseif ($amountPaid < $totalFee) {

            $paymentStatus = 'Partial';

        } else {

            $paymentStatus = 'Paid';
        }


        // ========================================
        // UPDATE DATABASE
        // ========================================

        if (empty($errors)) {

            $this->tableGateway->update(

                [

                    'admission_number' =>
                        $admissionNumber,

                    'name' =>
                        $name,

                    'date_of_birth' =>
                        ($dateOfBirth !== '')
                            ? $dateOfBirth
                            : null,

                    'email' =>
                        ($email !== '')
                            ? $email
                            : null,

                    'phone' =>
                        ($phone !== '')
                            ? $phone
                            : null,

                    'alternate_phone' =>
                        ($alternatePhone !== '')
                            ? $alternatePhone
                            : null,

                    'course' =>
                        $course,

                    'gender' =>
                        ($gender !== '')
                            ? $gender
                            : null,

                    'address' =>
                        ($address !== '')
                            ? $address
                            : null,

                    'city' =>
                        ($city !== '')
                            ? $city
                            : null,

                    'state' =>
                        ($state !== '')
                            ? $state
                            : null,

                    'pincode' =>
                        ($pincode !== '')
                            ? $pincode
                            : null,

                    'latitude' =>
                        ($latitude !== '')
                            ? $latitude
                            : null,

                    'longitude' =>
                        ($longitude !== '')
                            ? $longitude
                            : null,

                    'admission_date' =>
                        ($admissionDate !== '')
                            ? $admissionDate
                            : null,

                    'batch' =>
                        ($batch !== '')
                            ? $batch
                            : null,

                    'previous_qualification' =>
                        ($previousQualification !== '')
                            ? $previousQualification
                            : null,

                    'previous_institution' =>
                        ($previousInstitution !== '')
                            ? $previousInstitution
                            : null,

                    'parent_name' =>
                        ($parentName !== '')
                            ? $parentName
                            : null,

                    'parent_phone' =>
                        ($parentPhone !== '')
                            ? $parentPhone
                            : null,

                    'admission_status' =>
                        $admissionStatus,

                    'total_fee' =>
                        $totalFee,

                    'amount_paid' =>
                        $amountPaid,

                    'payment_status' =>
                        $paymentStatus

                ],

                ['id' => $id]
            );


            // ========================================
            // ACTIVITY LOG
            // ========================================

            $this->logActivity(
                'Edited student ID: '
                . $id
                . ' - Admission No: '
                . $admissionNumber
            );


            // ========================================
            // SUCCESS MESSAGE
            // ========================================

            $this->flashMessenger()
                ->addSuccessMessage(
                    'Student updated successfully!'
                );


            return $this->redirect()->toRoute(
                'student'
            );
        }


        // Keep submitted data if validation fails
        $student = array_merge(
            (array) $student,
            $data
        );


        return new ViewModel([
            'student' => $student,
            'errors' => $errors
        ]);
    }


    // ========================================
    // DISPLAY EDIT FORM
    // ========================================

    return new ViewModel([
        'student' => $student,
        'errors' => []
    ]);
}
//delete student
public function deleteAction()
{
    if (($_SESSION['role'] ?? '') !== 'admin') {
    return $this->redirect()->toRoute('student', [
        'action' => 'index'
    ]);
}
    $id = (int) $this->params()->fromRoute('id', 0);

    if ($id === 0) {
        return $this->redirect()->toRoute('student');
    }

    $student = $this->tableGateway
        ->select(['id' => $id])
        ->current();

    if (!$student) {
        return $this->redirect()->toRoute('student');
    }

    if ($this->getRequest()->isPost()) {

       $this->tableGateway->delete([
            'id' => $id
        ]);

        $this->logActivity('Deleted student ID: ' . $id);

        $this->flashMessenger()->addSuccessMessage(
            'Student deleted successfully.'
        );

        return $this->redirect()->toRoute('student');
    }

    return new ViewModel([
        'student' => $student
    ]);
}public function dashboardAction()
{
    if (empty($_SESSION['logged_in'])) {
        return $this->redirect()->toRoute('student', [
            'action' => 'login'
        ]);
    }

    // ========================================
    // STUDENT STATISTICS
    // ========================================

    // Total students
    $totalStudents = $this->tableGateway
        ->select()
        ->count();
    // ========================================
// COURSE STATISTICS
// ========================================

$javaFullStackCount = $this->tableGateway
    ->select([
        'course' => 'Java Full Stack'
    ])
    ->count();

$pythonCount = $this->tableGateway
    ->select([
        'course' => 'Python'
    ])
    ->count();

$dataScienceCount = $this->tableGateway
    ->select([
        'course' => 'Data Science'
    ])
    ->count();

$webDevelopmentCount = $this->tableGateway
    ->select([
        'course' => 'Web Development'
    ])
    ->count();    


    // ========================================
    // USER STATISTICS
    // ========================================

    // Total users
    $totalUsers = $this->userTableGateway
        ->select()
        ->count();

    // Total admins
    $totalAdmins = $this->userTableGateway
        ->select([
            'role' => 'admin'
        ])
        ->count();

    // Total normal users
    $totalNormalUsers = $this->userTableGateway
        ->select([
            'role' => 'user'
        ])
        ->count();

    // Active users
    $activeUsers = $this->userTableGateway
        ->select([
            'status' => 'active'
        ])
        ->count();

    // Inactive users
    $inactiveUsers = $this->userTableGateway
        ->select([
            'status' => 'inactive'
        ])
        ->count();


    // ========================================
    // ADMISSION STATISTICS
    // ========================================

    // Total Admissions
    $totalAdmissions = $this->tableGateway
        ->select()
        ->count();


    // Active Admissions
    $activeAdmissions = $this->tableGateway
        ->select([
            'admission_status' => 'Active'
        ])
        ->count();


    // Pending Admissions
    $pendingAdmissions = $this->tableGateway
        ->select([
            'admission_status' => 'Pending'
        ])
        ->count();


    // Completed Admissions
    $completedAdmissions = $this->tableGateway
        ->select([
            'admission_status' => 'Completed'
        ])
        ->count();


    // Cancelled Admissions
    $cancelledAdmissions = $this->tableGateway
        ->select([
            'admission_status' => 'Cancelled'
        ])
        ->count();


    // ========================================
    // FEE STATISTICS
    // ========================================

    $totalFees = 0;
    $amountCollected = 0;

    $students = $this->tableGateway->select();

    foreach ($students as $student) {

        $totalFees += (float) ($student['total_fee'] ?? 0);
        $amountCollected += (float) ($student['amount_paid'] ?? 0);
    }


    // Pending Fee Amount
    $pendingFeeAmount = $totalFees - $amountCollected;


    // Prevent negative value
    if ($pendingFeeAmount < 0) {
        $pendingFeeAmount = 0;
    }


    // ========================================
    // SEND DATA TO DASHBOARD VIEW
    // ========================================

    return new ViewModel([

        // Existing student statistics
        'totalStudents'    => $totalStudents,

        'javaFullStackCount' => $javaFullStackCount,
        'pythonCount' => $pythonCount,
        'dataScienceCount' => $dataScienceCount,
        'webDevelopmentCount' => $webDevelopmentCount,

        // Existing user statistics
        'totalUsers'       => $totalUsers,
        'totalAdmins'      => $totalAdmins,
        'totalNormalUsers' => $totalNormalUsers,
        'activeUsers'      => $activeUsers,
        'inactiveUsers'    => $inactiveUsers,

        // Admission statistics
        'totalAdmissions'   => $totalAdmissions,
        'activeAdmissions'  => $activeAdmissions,
        'pendingAdmissions' => $pendingAdmissions,
        'completedAdmissions' => $completedAdmissions,
        'cancelledAdmissions' => $cancelledAdmissions,

        // Fee statistics
        'totalFees'         => $totalFees,
        'amountCollected'   => $amountCollected,
        'pendingFeeAmount'  => $pendingFeeAmount,
    ]);
}
public function signupAction()
{
    // ========================================
    // ALREADY LOGGED IN
    // ========================================

    if (!empty($_SESSION['logged_in'])) {
        return $this->redirect()->toRoute('student', [
            'action' => 'dashboard'
        ]);
    }


    // ========================================
    // FORM SUBMISSION
    // ========================================

    if ($this->getRequest()->isPost()) {

        $data = $this->params()->fromPost();

        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';

        $errors = [];


        // ========================================
        // VALIDATION
        // ========================================

        if ($username === '') {
            $errors[] = 'Username is required.';
        }

        if ($password === '') {
            $errors[] = 'Password is required.';
        }

        if (strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters.';
        }

        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }

        if ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }


        // ========================================
        // CHECK USERNAME
        // ========================================

        if ($username !== '') {

            $existingUser = $this->userTableGateway
                ->select([
                    'username' => $username
                ])
                ->current();

            if ($existingUser) {
                $errors[] = 'Username already exists.';
            }
        }


        // ========================================
        // CREATE USER
        // ========================================

        if (empty($errors)) {

            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $this->userTableGateway->insert([
                'username' => $username,
                'password' => $hashedPassword,
                'role' => 'user',
                'status' => 'active'
            ]);

            return $this->redirect()->toRoute('student', [
                'action' => 'login'
            ]);
        }


        return new ViewModel([
            'errors' => $errors,
            'username' => $username
        ]);
    }


    return new ViewModel([
        'errors' => [],
        'username' => ''
    ]);
}
public function loginAction()
{
    if ($this->getRequest()->isPost()) {

        $data = $this->params()->fromPost();

        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';

        if ($username === '' || $password === '') {

            return new ViewModel([
                'error' => 'Username and password are required.'
            ]);
        }

        // Find user from database
        $user = $this->userTableGateway
        ->select([
            'username' => $username
        ])
        ->current();

        // Verify password
   if ($user && password_verify($password, $user['password'])) {

    // Check whether the account is active
    if (($user['status'] ?? 'active') !== 'active') {

        return new ViewModel([
            'error' => 'Your account is inactive. Please contact the administrator.'
        ]);
    }

    session_regenerate_id(true);

    // Update last login time
    $this->userTableGateway->update(
        [
            'last_login' => date('Y-m-d H:i:s')
        ],
        [
            'id' => $user['id']
        ]
    );

    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'] ?? 'user';
    $this->logActivity('Logged in');

    return $this->redirect()->toRoute('student', [
        'action' => 'dashboard'
    ]);
}

        return new ViewModel([

        
            'error' => 'Invalid username or password.'
        ]);
    }

    return new ViewModel();
}
public function forgotPasswordAction()
{
    if ($this->getRequest()->isPost()) {

        $data = $this->params()->fromPost();

        $username = trim($data['username'] ?? '');
        $newPassword = $data['new_password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';

        // Check username
        if ($username === '') {
            return new ViewModel([
                'error' => 'Username is required.'
            ]);
        }

        // Check new password
        if ($newPassword === '') {
            return new ViewModel([
                'error' => 'New password is required.',
                'username' => $username
            ]);
        }

        // Minimum password length
        if (strlen($newPassword) < 6) {
            return new ViewModel([
                'error' => 'Password must contain at least 6 characters.',
                'username' => $username
            ]);
        }

        // Check confirmation
        if ($newPassword !== $confirmPassword) {
            return new ViewModel([
                'error' => 'Passwords do not match.',
                'username' => $username
            ]);
        }

        // Find user
        $user = $this->userTableGateway
            ->select([
                'username' => $username
            ])
            ->current();

        // User does not exist
        if (!$user) {
            return new ViewModel([
                'error' => 'Username not found.'
            ]);
        }

        // Hash the new password
        $hashedPassword = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        // Update password
        $this->userTableGateway->update(
            [
                'password' => $hashedPassword
            ],
            [
                'id' => $user['id']
            ]
        );

        // Success message
        return new ViewModel([
            'success' => 'Password updated successfully. You can now login.'
        ]);
    }

    return new ViewModel();
}
public function changePasswordAction()
{
    // User must be logged in
    if (empty($_SESSION['logged_in'])) {
        return $this->redirect()->toRoute('student', [
            'action' => 'login'
        ]);
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        return $this->redirect()->toRoute('student', [
            'action' => 'login'
        ]);
    }

    if ($this->getRequest()->isPost()) {

        $data = $this->params()->fromPost();

        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';

        // Check current password
        if ($currentPassword === '') {
            return new ViewModel([
                'error' => 'Current password is required.'
            ]);
        }

        // Check new password
        if ($newPassword === '') {
            return new ViewModel([
                'error' => 'New password is required.'
            ]);
        }

        // Check password length
        if (strlen($newPassword) < 6) {
            return new ViewModel([
                'error' => 'Password must contain at least 6 characters.'
            ]);
        }

        // Check uppercase letter
        if (!preg_match('/[A-Z]/', $newPassword)) {
            return new ViewModel([
                'error' => 'Password must contain at least one uppercase letter.'
            ]);
        }

        // Check number
        if (!preg_match('/[0-9]/', $newPassword)) {
            return new ViewModel([
                'error' => 'Password must contain at least one number.'
            ]);
        }

        // Confirm password
        if ($newPassword !== $confirmPassword) {
            return new ViewModel([
                'error' => 'Passwords do not match.'
            ]);
        }

        // Get logged-in user
        $user = $this->userTableGateway
            ->select([
                'id' => $userId
            ])
            ->current();

        if (!$user) {
            $_SESSION = [];

            return $this->redirect()->toRoute('student', [
                'action' => 'login'
            ]);
        }

        // Verify current password
        if (!password_verify($currentPassword, $user['password'])) {
            return new ViewModel([
                'error' => 'Current password is incorrect.'
            ]);
        }

        // Prevent using the same password
        if (password_verify($newPassword, $user['password'])) {
            return new ViewModel([
                'error' => 'New password must be different from the current password.'
            ]);
        }

        // Hash new password
        $hashedPassword = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        // Update password
        $this->userTableGateway->update(
            [
                'password' => $hashedPassword
            ],
            [
                'id' => $userId
            ]
        );
        $this->logActivity('Changed password');

        return new ViewModel([
            'success' => 'Password changed successfully.'
        ]);
    }

    return new ViewModel();
}
public function profileAction()
{
    if (empty($_SESSION['logged_in'])) {
        return $this->redirect()->toRoute('student', [
            'action' => 'login'
        ]);
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        $_SESSION = [];

        return $this->redirect()->toRoute('student', [
            'action' => 'login'
        ]);
    }

    $user = $this->userTableGateway
        ->select([
            'id' => $userId
        ])
        ->current();

    if (!$user) {
        $_SESSION = [];

        return $this->redirect()->toRoute('student', [
            'action' => 'login'
        ]);
    }

    return new ViewModel([
        'user' => $user
    ]);
}
public function usersAction()
{
    if (($_SESSION['role'] ?? '') !== 'admin') {
        return $this->redirect()->toRoute('student', [
            'action' => 'dashboard'
        ]);
    }

    $search = trim(
        $this->params()->fromQuery('search', '')
    );

    $role = trim(
        $this->params()->fromQuery('role', '')
    );

    $select = new \Laminas\Db\Sql\Select('users');

    // Search by username
    if ($search !== '') {
        $select->where
            ->like('username', '%' . $search . '%');
    }

    // Filter by role
    if (in_array($role, ['admin', 'user'], true)) {
        $select->where([
            'role' => $role
        ]);
    }

    $select->order('id ASC');

    $users = $this->userTableGateway
        ->selectWith($select);

    return new ViewModel([
        'users'  => $users,
        'search' => $search,
        'role'   => $role,
    ]);
}
public function logoutAction()
{
    // Log logout before destroying the session
    $this->logActivity('Logged out');

    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    return $this->redirect()->toRoute('student', [
        'action' => 'login'
    ]);
}
public function addUserAction()
{
    if (($_SESSION['role'] ?? '') !== 'admin') {
    return $this->redirect()->toRoute('student', [
        'action' => 'dashboard'
    ]);
}
    $errors = [];

    if ($this->getRequest()->isPost()) {

        $data = $this->params()->fromPost();

        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';

        // Username validation
        if ($username === '') {
            $errors[] = 'Username is required.';
        }

        // Password validation
        if (strlen($password) < 6) {
            $errors[] = 'Password must contain at least 6 characters.';
        }

        // Confirm password
        if ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }

        // Duplicate username
        $existingUser = $this->userTableGateway
            ->select([
                'username' => $username
            ])
            ->current();

        if ($existingUser) {
            $errors[] = 'This username already exists.';
        }

        if (empty($errors)) {

            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

           $this->userTableGateway->insert([
                'username' => $username,
                'password' => $hashedPassword,
               
            ]);

            $this->logActivity('Added user: ' . $username);

            $this->flashMessenger()->addSuccessMessage(
                'User added successfully.'
            );

            return $this->redirect()->toRoute('student', [
                'action' => 'users'
            ]);
        }
    }

    return new ViewModel([
        'errors' => $errors
    ]);
}
public function deleteUserAction()
{
    if (($_SESSION['role'] ?? '') !== 'admin') {
    return $this->redirect()->toRoute('student', [
        'action' => 'dashboard'
    ]);
}
    $id = (int) $this->params()->fromRoute('id', 0);

    if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
    $this->flashMessenger()->addErrorMessage(
        'You cannot delete your own account.'
    );

    return $this->redirect()->toRoute('student', [
        'action' => 'users'
    ]);
}

    if ($id === 0) {
        return $this->redirect()->toRoute('student', [
            'action' => 'users'
        ]);
    }

    $user = $this->userTableGateway
        ->select(['id' => $id])
        ->current();

    if (!$user) {
        return $this->redirect()->toRoute('student', [
            'action' => 'users'
        ]);
    }

    // Don't allow deleting the currently logged-in user
    if ($user['username'] === ($_SESSION['username'] ?? '')) {
        $this->flashMessenger()->addErrorMessage(
            'You cannot delete the currently logged-in user.'
        );

        return $this->redirect()->toRoute('student', [
            'action' => 'users'
        ]);
    }

    if ($this->getRequest()->isPost()) {

        $this->userTableGateway->delete([
            'id' => $id
        ]);
        $this->logActivity('Deleted user ID: ' . $id);

        $this->flashMessenger()->addSuccessMessage(
            'User deleted successfully!'
        );

        return $this->redirect()->toRoute('student', [
            'action' => 'users'
        ]);
    }

    return new ViewModel([
        'user' => $user
    ]);
}
public function toggleUserStatusAction()
{
    // Only administrators can change user status
    if (($_SESSION['role'] ?? '') !== 'admin') {

        $this->flashMessenger()->addErrorMessage(
            'Only administrators can change user status.'
        );

        return $this->redirect()->toRoute('student', [
            'action' => 'dashboard'
        ]);
    }

    $userId = (int) $this->params()->fromRoute('id', 0);

    if ($userId <= 0) {
        return $this->redirect()->toRoute('student', [
            'action' => 'users'
        ]);
    }

    // Prevent admin from deactivating their own account
    if ($userId === (int) ($_SESSION['user_id'] ?? 0)) {

        $this->flashMessenger()->addErrorMessage(
            'You cannot deactivate your own account.'
        );

        return $this->redirect()->toRoute('student', [
            'action' => 'users'
        ]);
    }

    $user = $this->userTableGateway
        ->select([
            'id' => $userId
        ])
        ->current();

    if (!$user) {

        $this->flashMessenger()->addErrorMessage(
            'User not found.'
        );

        return $this->redirect()->toRoute('student', [
            'action' => 'users'
        ]);
    }

    $currentStatus = $user['status'] ?? 'active';

    $newStatus = ($currentStatus === 'active')
        ? 'inactive'
        : 'active';

    $this->userTableGateway->update(
        [
            'status' => $newStatus
        ],
        [
            'id' => $userId
        ]
    );
    $this->logActivity(
    'Changed user ID ' . $userId . ' status to ' . ucfirst($newStatus)
);

    $this->flashMessenger()->addSuccessMessage(
        'User status changed to ' . ucfirst($newStatus) . '.'
    );

    return $this->redirect()->toRoute('student', [
        'action' => 'users'
    ]);
}

public function editUserAction()
{
    if (($_SESSION['role'] ?? '') !== 'admin') {
    return $this->redirect()->toRoute('student', [
        'action' => 'dashboard'
    ]);
}
    $id = (int) $this->params()->fromRoute('id', 0);

    if ($id === 0) {
        return $this->redirect()->toRoute('student', [
            'action' => 'users'
        ]);
    }

    $user = $this->userTableGateway
        ->select(['id' => $id])
        ->current();

    if (!$user) {
        return $this->redirect()->toRoute('student', [
            'action' => 'users'
        ]);
    }

    $errors = [];

    if ($this->getRequest()->isPost()) {

        $data = $this->params()->fromPost();

        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';
        $role = $data['role'] ?? 'user';

        if ($username === '') {
            $errors[] = 'Username is required.';
        }

        if ($password !== '' && strlen($password) < 6) {
            $errors[] = 'Password must contain at least 6 characters.';
        }

        if ($password !== '' && $password !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }
        if (!in_array($role, ['admin', 'user'], true)) {
    $role = 'user';
}

        // Check whether another user already has this username
        $existingUser = $this->userTableGateway
            ->select([
                'username' => $username
                
            ])
            ->current();

        if ($existingUser && $existingUser->id != $id) {
            $errors[] = 'This username already exists.';
        }

        if (empty($errors)) {

            $updateData = [
                'username' => $username,
                'role'     => $role
            ];

            // Only change password if a new password was entered
            if ($password !== '') {
                $updateData['password'] = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );
            }

            $this->userTableGateway->update(
                $updateData,
                ['id' => $id]
            );
           $this->logActivity(
                'Edited user ID ' . $id . ' - Role: ' . ucfirst($role)
            );

            // Update session username if editing current user
            if (
                ($_SESSION['username'] ?? '') === $user->username
            ) {
                $_SESSION['username'] = $username;
            }

            $this->flashMessenger()->addSuccessMessage(
                'User updated successfully!'
            );

            return $this->redirect()->toRoute('student', [
                'action' => 'users'
            ]);
        }
    }

    return new ViewModel([
        'user' => $user,
        'errors' => $errors
    ]);
}
}
