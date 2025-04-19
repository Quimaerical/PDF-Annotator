<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PdfAnnotationController;

Route::get('/', [PdfAnnotationController::class, 'index'])->name('home');
Route::post('/upload', [PdfAnnotationController::class, 'uploadPdf'])->name('upload.pdf');
Route::post('/export', [PdfAnnotationController::class, 'exportImage'])->name('export.image');