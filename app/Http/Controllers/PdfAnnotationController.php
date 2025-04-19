<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use illuminate\Support\Facades\Log;


class PdfAnnotationController extends Controller
{
    public function index(){
        return view('main_form');
    }

    public function uploadPDF(Request $request)
    {
        // Validación de la solicitud
        $request->validate([
            'pdf_file' => 'required|mimes:pdf|max:100000', // Ajusta el tamaño si es necesario
        ]);

        // Si la validación pasa y hay un archivo
        if ($request->hasFile('pdf_file')) {
            $pdfFile = $request->file('pdf_file');

            // Sanitizar el nombre original del archivo para evitar problemas de URL o sistema de archivos
            $originalFilename = pathinfo($pdfFile->getClientOriginalName(), PATHINFO_FILENAME);
            $sanitizedFilename = Str::slug($originalFilename, '_'); // Convierte a slug, usando '_' como separador
            $extension = $pdfFile->getClientOriginalExtension();

            // Generar un nombre único para evitar colisiones
            $filename = $sanitizedFilename . '_' . time() . '.' . $extension;

            // Guardar el archivo en el disco 'public' dentro del directorio 'pdfs'
            // Storage::putFileAs() es una alternativa más robusta
            $pdfPath = $pdfFile->storeAs('pdfs', $filename, 'public');

            // Generar la URL pública para acceder al archivo guardado
            // asset() genera la URL base correcta para tu aplicación
            $pdfUrl = asset('storage/pdfs/' . $filename);

            return response()->json([
                'pdfUrl' => $pdfUrl,
                'filename' => pathinfo($filename, PATHINFO_FILENAME) // Devolver solo el nombre base sin extensión
            ]);
        }

        // Si por alguna razón no hay archivo a pesar de la validación (poco probable)
        return response()->json(['error' => 'No PDF file received.'], 400);
    }



    // public function uploadPDF(Request $request){
    //     $request->validate([
    //         'pdf_file' => 'required|mimes:pdf|max:2048', // Asegúrate de que el nombre del input sea 'pdf_file'
    //     ]);

    //     if ($request->hasFile('pdf_file')) { // Verifica si se subió un archivo
    //         $pdfFile = $request->file('pdf_file');
    //         $filename = time() . '_' . $pdfFile->getClientOriginalName();
    //         $pdfPath = $pdfFile->storeAs('pdfs', $filename, 'public');
    //         $pdfUrl = asset('storage/app/public/pdfs/' . $filename);

    //        return view('main_form', ['pdfUrl' => $pdfUrl, 'filename' => pathinfo($filename, PATHINFO_FILENAME)]);

    //     }

    //     return response()->json(['error' => 'No PDF received.'], 400); // En caso de que no se suba el archivo
    // }

    public function exportImage(Request $request)
    {
        // Validar que los datos necesarios estén presentes
        $request->validate([
            'image_data' => 'required|string',
            'filename' => 'required|string',
        ]);

        $imageData = $request->input('image_data');
        $filename = $request->input('filename'); // Nombre base del archivo original

        // Limpiar y decodificar la imagen base64
        $base64Image = str_replace('data:image/png;base64,', '', $imageData);
        $base64Image = str_replace(' ', '+', $base64Image);

        $image = base64_decode($base64Image);

        // Verificar si la decodificación Base64 falló
        if ($image === false) {
            Log::error("Base64 decode failed for image export.");
            return response()->json(['error' => 'Failed to decode image data.'], 400);
        }

        // Generar un nombre único para el archivo de imagen guardado
        $sanitizedFilenamePart = Str::slug($filename, '_'); // Convierte a slug usando '_'
        $imageName = 'annotated_' . $sanitizedFilenamePart . '_' . time() . '.png';

        // Definir la ruta donde se guardará el archivo dentro del disco 'public'
        // La ruta 'annotated/' es relativa a la raíz del disco 'public' (storage/app/public)
        $storagePath = 'annotated/' . $imageName;

        // ****** USAR FACADE STORAGE CON TRY/CATCH ******
        try {
            // Guardar la imagen en el disco 'public'
            // Storage::disk('public') asegura que se use el disco configurado como 'public'
            // Storage::put() automáticamente intentará crear los directorios necesarios
            Storage::disk('public')->put($storagePath, $image);

        } catch (\Exception $e) {
            // Capturar cualquier excepción durante el proceso de guardado
            // Registrar el error detallado en storage/logs/laravel.log
            Log::error("Error saving annotated image using Storage facade: " . $e->getMessage());

            // Devolver una respuesta JSON con estado 500 al cliente
            return response()->json(['error' => 'Failed to save image on server. Please check server logs.'], 500);
        }
        // ****** FIN DEL USO DEL FACADE STORAGE ******


        // Si el guardado fue exitoso, generar la URL pública
        // asset('storage/...') genera la URL web correcta si el enlace simbólico existe
        $imageUrl = asset('storage/' . $storagePath); // La ruta asset('storage/...') es relativa a la carpeta 'public' del proyecto

        // Devolver la URL de la imagen guardada en formato JSON al cliente
        return response()->json(['url' => $imageUrl]);
    }
}