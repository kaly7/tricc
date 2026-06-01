<?php
declare(strict_types=1);

require __DIR__ . '/../app/config/config.php';

date_default_timezone_set(APP_TIMEZONE);

session_name(SESSION_COOKIE);
session_name('FEJLESZTES_SESSID');
session_start();

require APP_ROOT . '/app/core/Db.php';
require APP_ROOT . '/app/core/Router.php';
require APP_ROOT . '/app/core/View.php';
require APP_ROOT . '/app/core/Flash.php';
require APP_ROOT . '/app/core/Csrf.php';
require APP_ROOT . '/app/core/Auth.php';

require APP_ROOT . '/app/controllers/AuthController.php';
require APP_ROOT . '/app/controllers/UsersController.php';
require APP_ROOT . '/app/controllers/EmployeesController.php';
require APP_ROOT . '/app/controllers/DivisionsController.php';
require APP_ROOT . '/app/controllers/DocTypesController.php';
require APP_ROOT . '/app/controllers/DocumentsController.php';
require APP_ROOT . '/app/controllers/FieldsController.php';
require APP_ROOT . '/app/controllers/HrPermissionsController.php';
require APP_ROOT . '/app/core/HrPermission.php';

$db    = new Db();
$view  = new View();
$flash = new Flash();
$csrf  = new Csrf();
$auth  = new Auth($db);

$router = new Router();

// Home
$router->get('/', function () use ($auth) {
  if (!$auth->check()) {
    header('Location: /login');
    exit;
  }
  header('Location: /employees');
  exit;
});

// Auth
$authController = new AuthController($db, $view, $flash, $csrf, $auth);
$router->get('/login', function() use ($authController){ $authController->showLogin(); });
$router->post('/login', function() use ($authController){ $authController->doLogin(); });
$router->post('/logout', function() use ($authController){ $authController->logout(); });

// Users (admin)
$usersController = new UsersController($db, $view, $flash, $csrf, $auth);
$router->get('/users',        function() use ($usersController){ $usersController->index(); });
$router->get('/users_create', function() use ($usersController){ $usersController->showCreate(); });
$router->post('/users_create',function() use ($usersController){ $usersController->create(); });
$router->get('/users_edit',   function() use ($usersController){ $usersController->showEdit(); });
$router->post('/users_edit',  function() use ($usersController){ $usersController->update(); });
$router->post('/users_toggle',function() use ($usersController){ $usersController->toggleActive(); });

// Divisions (admin)
$divController = new DivisionsController($db, $view, $flash, $csrf, $auth);
$router->get('/divisions',        function() use ($divController){ $divController->index(); });
$router->post('/divisions_create', function() use ($divController){ $divController->create(); });
$router->post('/divisions_update', function() use ($divController){ $divController->update(); });
$router->post('/divisions_toggle', function() use ($divController){ $divController->toggle(); });

// Doc types (admin)
$dtController = new DocTypesController($db, $view, $flash, $csrf, $auth);
$router->get('/doctypes',        function() use ($dtController){ $dtController->index(); });
$router->post('/doctypes_create',function() use ($dtController){ $dtController->create(); });
$router->post('/doctypes_toggle',function() use ($dtController){ $dtController->toggle(); });

// Documents
$docController = new DocumentsController($db, $view, $flash, $csrf, $auth);
$router->get('/documents',        function() use ($docController){ $docController->index(); });
$router->get('/documents_upload', function() use ($docController){ $docController->showUpload(); });
$router->post('/documents_upload',function() use ($docController){ $docController->upload(); });
$router->post('/documents_delete',function() use ($docController){ $docController->delete(); });
$router->post('/documents_edit',  function() use ($docController){ $docController->update(); });

// Employees
$empController = new EmployeesController($db, $view, $flash, $csrf, $auth);
$router->get('/employees',        function() use ($empController){ $empController->index(); });
$router->get('/employees_export', function() use ($empController){ $empController->showExport(); });
$router->post('/employees_export',function() use ($empController){ $empController->export(); });
$router->get('/employees_create', function() use ($empController){ $empController->showCreate(); });
$router->post('/employees_create',function() use ($empController){ $empController->create(); });
$router->get('/employees_view',   function() use ($empController){ $empController->showView(); });
$router->get('/employees_pdf',    function() use ($empController){ $empController->pdf(); });
$router->get('/employees_edit',   function() use ($empController){ $empController->showEdit(); });
$router->post('/employees_edit',  function() use ($empController){ $empController->update(); });
$router->post('/employees_toggle',function() use ($empController){ $empController->toggleActive(); });
$router->post('/employees_delete',function() use ($empController){ $empController->delete(); });

// ===== Extra mezők (admin) =====
$fieldsController = new FieldsController($db, $view, $flash, $csrf, $auth);
$router->get('/fields',        function() use ($fieldsController){ $fieldsController->index(); });
$router->get('/fields_create', function() use ($fieldsController){ $fieldsController->showCreate(); });
$router->post('/fields_create',function() use ($fieldsController){ $fieldsController->create(); });
$router->get('/fields_edit',   function() use ($fieldsController){ $fieldsController->showEdit(); });
$router->post('/fields_edit',  function() use ($fieldsController){ $fieldsController->edit(); });
$router->post('/fields_toggle',function() use ($fieldsController){ $fieldsController->toggle(); });

// HR Permissions (admin)
$permCtrl = new HrPermissionsController($db, $view, $flash, $csrf, $auth);
$router->get('/hr_permissions',       function() use ($permCtrl){ $permCtrl->index(); });
$router->get('/hr_permissions_edit',  function() use ($permCtrl){ $permCtrl->showEdit(); });
$router->post('/hr_permissions_save', function() use ($permCtrl){ $permCtrl->save(); });
$router->post('/hr_permissions_delete',function() use ($permCtrl){ $permCtrl->delete(); });
$router->get('/hr_audit_log',         function() use ($permCtrl){ $permCtrl->auditLog(); });

$router->dispatch();
