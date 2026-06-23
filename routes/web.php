<?php

use App\Http\Controllers\Auth\DevLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SsoCallbackController;
use App\Http\Controllers\ArCreditNoteController;
use App\Http\Controllers\ArInvoiceController;
use App\Http\Controllers\ArPaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'redirect'])->name('login');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
Route::get('/sso/callback', SsoCallbackController::class)->name('sso.callback');

Route::get('/dev-login', [DevLoginController::class, 'index'])->name('dev-login.index');
Route::post('/dev-login/{user}', [DevLoginController::class, 'login'])->name('dev-login.login');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // --- Users (sso_admin only, enforced in controller via middleware below) ---
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update')->middleware('role:sso_admin,admin');

    // --- Reports ---
    Route::get('reports/open-receivables', [ReportController::class, 'openReceivables'])->name('reports.open-receivables');
    Route::get('reports/aged-receivables', [ReportController::class, 'agedReceivables'])->name('reports.aged-receivables');
    Route::get('reports/aged-receivables-summary', [ReportController::class, 'agedReceivablesSummary'])->name('reports.aged-receivables-summary');
    Route::get('reports/ar-history', [ReportController::class, 'arHistory'])->name('reports.ar-history');

    // --- AR Invoices: static routes registered before {invoice} (show) ---
    Route::get('ar-invoices', [ArInvoiceController::class, 'index'])->name('ar-invoices.index');
    Route::get('ar-invoices/create', [ArInvoiceController::class, 'createPicker'])->name('ar-invoices.create')->middleware('role:admin,user');
    Route::get('ar-invoices/create-manual', [ArInvoiceController::class, 'create'])->name('ar-invoices.create-manual')->middleware('role:admin,user');
    Route::post('ar-invoices/create-from-so/{salesOrder}', [ArInvoiceController::class, 'createFromSalesOrder'])->name('ar-invoices.create-from-so')->middleware('role:admin,user');
    Route::post('ar-invoices', [ArInvoiceController::class, 'store'])->name('ar-invoices.store')->middleware('role:admin,user');
    Route::get('ar-invoices/{invoice}', [ArInvoiceController::class, 'show'])->name('ar-invoices.show');
    Route::get('ar-invoices/{invoice}/edit', [ArInvoiceController::class, 'edit'])->name('ar-invoices.edit')->middleware('role:admin,user');
    Route::put('ar-invoices/{invoice}', [ArInvoiceController::class, 'update'])->name('ar-invoices.update')->middleware('role:admin,user');
    Route::delete('ar-invoices/{invoice}', [ArInvoiceController::class, 'destroy'])->name('ar-invoices.destroy')->middleware('role:admin,user');
    Route::post('ar-invoices/{invoice}/submit', [ArInvoiceController::class, 'submit'])->name('ar-invoices.submit')->middleware('role:admin,user');
    Route::post('ar-invoices/{invoice}/approve', [ArInvoiceController::class, 'approve'])->name('ar-invoices.approve')->middleware('role:admin,approval');
    Route::post('ar-invoices/{invoice}/reject', [ArInvoiceController::class, 'reject'])->name('ar-invoices.reject')->middleware('role:admin,approval');
    Route::post('ar-invoices/{invoice}/mark-printed', [ArInvoiceController::class, 'markPrinted'])->name('ar-invoices.mark-printed')->middleware('role:admin,user');

    // --- AR Payments ---
    Route::get('ar-payments', [ArPaymentController::class, 'index'])->name('ar-payments.index');
    Route::get('ar-payments/create', [ArPaymentController::class, 'create'])->name('ar-payments.create')->middleware('role:admin,user');
    Route::post('ar-payments', [ArPaymentController::class, 'store'])->name('ar-payments.store')->middleware('role:admin,user');
    Route::get('ar-payments/{payment}', [ArPaymentController::class, 'show'])->name('ar-payments.show');
    Route::delete('ar-payments/{payment}', [ArPaymentController::class, 'destroy'])->name('ar-payments.destroy')->middleware('role:admin,user');
    Route::post('ar-payments/{payment}/submit', [ArPaymentController::class, 'submit'])->name('ar-payments.submit')->middleware('role:admin,user');
    Route::post('ar-payments/{payment}/approve', [ArPaymentController::class, 'approve'])->name('ar-payments.approve')->middleware('role:admin,approval');
    Route::post('ar-payments/{payment}/reject', [ArPaymentController::class, 'reject'])->name('ar-payments.reject')->middleware('role:admin,approval');
    Route::post('ar-payments/{payment}/mark-reconciled', [ArPaymentController::class, 'markReconciled'])->name('ar-payments.mark-reconciled')->middleware('role:admin,user');

    // --- AR Credit Notes ---
    Route::get('ar-credit-notes', [ArCreditNoteController::class, 'index'])->name('ar-credit-notes.index');
    Route::get('ar-credit-notes/create', [ArCreditNoteController::class, 'create'])->name('ar-credit-notes.create')->middleware('role:admin,user');
    Route::post('ar-credit-notes', [ArCreditNoteController::class, 'store'])->name('ar-credit-notes.store')->middleware('role:admin,user');
    Route::get('ar-credit-notes/{creditNote}', [ArCreditNoteController::class, 'show'])->name('ar-credit-notes.show');
    Route::delete('ar-credit-notes/{creditNote}', [ArCreditNoteController::class, 'destroy'])->name('ar-credit-notes.destroy')->middleware('role:admin,user');
    Route::post('ar-credit-notes/{creditNote}/submit', [ArCreditNoteController::class, 'submit'])->name('ar-credit-notes.submit')->middleware('role:admin,user');
    Route::post('ar-credit-notes/{creditNote}/approve', [ArCreditNoteController::class, 'approve'])->name('ar-credit-notes.approve')->middleware('role:admin,approval');
    Route::post('ar-credit-notes/{creditNote}/reject', [ArCreditNoteController::class, 'reject'])->name('ar-credit-notes.reject')->middleware('role:admin,approval');
});
