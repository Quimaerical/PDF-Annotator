<!DOCTYPE html>
<html>

<head>
    <title>PDF Annotation</title>
    <script src="https://unpkg.com/konva@9/konva.min.js"></script>
    <link rel="stylesheet" href="{{ asset('css/main.css') }}">
    <link rel="stylesheet" href="{{ asset('css/normalize.css') }}">
    <style>
        body { 
            margin: 0; padding: 0; background-color: #000000; 
            color: #f0f0f0;
        }
        #container {
            width: 100%;
            text-align: center;
            margin-top: 20px;
        }
        #upload-section {
            margin-bottom: 20px;
        }
        #pdf-container {
            position: relative;
            display: inline-block;
        }
        #pdf-canvas {
            display: block; 
            border: 1px solid #aaa;
        }
        #konva-container {
            position: absolute;
            top: 0;
            left: 0;
            z-index: 10;
            border: 1px solid #ccc;
        }
        .controls {
            margin-top: 20px;
            text-align: center;
        }
        .control-item {
            margin: 0 10px;
        }
    </style>
</head>
<body>
    <div id="container">
        <div id="upload-section">
            <h1>Upload PDF</h1>

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul>
                        @foreach ($errors->all() as $error )
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form id="upload-form" action="{{ route('upload.pdf') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="file" name="pdf_file" id="pdf_file" class="button" required>
                <button type="submit" class="button">Upload</button>
            </form>
        </div>

        <div id="annotation-section" class="container">
            <h2>Annotate PDF</h2>
            <div id="pdf-container">
                <canvas id="pdf-canvas"></canvas>
                <div id="konva-container"></div>
            </div>

            <div class="controls">
                <div class="control-item">
                    <label for="add-text-input">Texto:</label>
                    <input type="text" id="add-text-input" style="color:#f0f0f0;">
                    <button id="add-text-button">Add Text</button>
                </div>

                <div class="control-item">
                    <label for="add-image-input">Add Image</label>
                    <input type="file" id="add-image-input" accept="image/*">
                </div>
                <div class="control-item">
                    <button id="export-button">Export Image</button>
                </div>
            </div>
        </div>
    </div>

    <script type="module">
        // ==============================================================
        // IMPORTACIONES DE MÓDULOS
        // ==============================================================
        // Importamos directamente las funciones y objetos necesarios
        // de las versiones .mjs de PDF.js
        import { getDocument, GlobalWorkerOptions } from 'https://unpkg.com/pdfjs-dist@5.1.91/build/pdf.min.mjs';
        // Konva ya expone Konva globalmente, así que no necesita importación especial aquí

        // ==============================================================
        // VARIABLES DE ESTADO - Ahora definidas en el ámbito del módulo
        // ==============================================================

        // CORRECCIÓN: Usar GlobalWorkerOptions importado, no pdfjsLib.GlobalWorkerOptions
        GlobalWorkerOptions.workerSrc = 'https://unpkg.com/pdfjs-dist@5.1.91/build/pdf.worker.min.mjs';

        var pdfUrl = null; // URL del PDF cargado
        var pdfDoc = null; // Objeto PDFDocumentProxy de PDF.js
        var pageNum = 1;   // Página actual mostrada
        var pageRendering = false; // Indica si una página se está renderizando
        var pageNumPending = null; // Número de página pendiente si se solicita otra durante el renderizado
        var pdfCanvas = document.getElementById('pdf-canvas'); // Elemento canvas del PDF
        var pdfCtx = pdfCanvas.getContext('2d'); // Contexto 2D del canvas del PDF
        var filename = null; // Nombre del archivo PDF (sin extensión)

        var width, height; // Dimensiones del canvas y el escenario Konva
        var stage = null; // Escenario Konva
        var layer = null; // Capa Konva


        // ==============================================================
        // FUNCIONES PRINCIPALES - Definidas en el ámbito del módulo
        // ==============================================================

        /**
         * Renderiza una página específica del PDF en el canvas.
         * @param {number} num El número de página a renderizar (base 1).
         */
        function renderPage(num) {
            pageRendering = true;
            if (layer) {
                layer.destroyChildren();
                layer.draw(); // Limpiar capa Konva antes de dibujar la nueva página (si existe)
            }


            pdfDoc.getPage(num).then(function(page) {
                var viewport = page.getViewport({ scale: 1.5 });
                pdfCanvas.height = viewport.height;
                pdfCanvas.width = viewport.width;

                width = pdfCanvas.width;
                height = pdfCanvas.height;

                var konvaContainer = document.getElementById('konva-container');
                if (!stage) {
                    stage = new Konva.Stage({
                        container: konvaContainer,
                        width: width,
                        height: height,
                    });
                    layer = new Konva.Layer();
                    stage.add(layer);
                } else {
                    stage.width(width);
                    stage.height(height);
                    konvaContainer.style.width = width + 'px';
                    konvaContainer.style.height = height + 'px';
                }
                 konvaContainer.style.width = width + 'px';
                 konvaContainer.style.height = height + 'px';


                var renderContext = {
                    canvasContext: pdfCtx,
                    viewport: viewport
                };

                var renderTask = page.render(renderContext);

                renderTask.promise.then(function() {
                    pageRendering = false;
                    if (pageNumPending !== null) {
                        renderPage(pageNumPending);
                        pageNumPending = null;
                    }
                    layer.draw(); // Dibujar la capa de Konva después de que el PDF se haya renderizado
                });
            });
        }

        /**
         * Carga un documento PDF desde una URL.
         * @param {string} url La URL del archivo PDF.
         */
        function loadPdf(url) {
            // CORRECCIÓN: Usar getDocument importado, no pdfjsLib.getDocument
            getDocument(url).promise.then(function(pdf) {
                pdfDoc = pdf;
                document.getElementById('pdf-canvas').style.display = 'block';
                document.getElementById('annotation-section').style.display = 'block';
                renderPage(pageNum);
            }).catch(function(error) {
                console.error('Error loading PDF:', error);
                alert('Error loading PDF after upload. Check console for details.');
                document.getElementById('annotation-section').style.display = 'none';
            });
        }

        /**
         * Añade un objeto de texto a la capa Konva.
         */
        function addText() {
             // CORRECCIÓN: Usar el ID correcto 'add-text-input' del input si lo cambiaste
            var textInput = document.getElementById('add-text-input');
            var textValue = textInput.value;
            if (textValue && stage && layer) {
                var textNode = new Konva.Text({
                    x: 50,
                    y: 50,
                    text: textValue,
                    fontSize: 20,
                    // CORRECCIÓN: Cambiar color del texto a algo visible
                    fill: '#000000', 
                    draggable: true,
                });
                layer.add(textNode);
                layer.draw();
                textInput.value = ''; // Limpiar el input después de añadir
            }
        }

         /**
         * Añade una imagen a la capa Konva desde un objeto File.
         * @param {File} file El archivo de imagen seleccionado.
         */
        function addImageFromFile(file) {
            if (file && stage && layer) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var imageObj = new Image();
                    imageObj.onload = function() {
                        var imageNode = new Konva.Image({
                            x: 50,
                            y: 50,
                            image: imageObj,
                            width: 100, // Tamaño inicial
                            height: 100, // Tamaño inicial
                            draggable: true,
                        });
                        layer.add(imageNode);
                        layer.draw();
                    };
                    imageObj.onerror = function() {
                        console.error("Error loading image for Konva", file.name);
                        alert("Could not load image: " + file.name);
                    };
                    imageObj.src = e.target.result;
                };
                reader.readAsDataURL(file); // Leer el archivo como URL de datos
            }
        }

        /**
         * Combina el canvas del PDF y el escenario Konva, y exporta como imagen.
         * Luego envía la imagen al servidor para guardarla.
         */
        async function exportCanvas() {
            if (!pdfDoc || !stage || !layer || !filename) {
                alert('PDF not loaded or something went wrong.');
                return;
            }

            layer.draw(); // Asegurarse de que Konva esté actualizado

            // 1. Obtener la Data URL de la capa Konva
            var konvaDataURL = layer.toDataURL();

            // 2. Obtener la Data URL del canvas del PDF
            var pdfDataURL = pdfCanvas.toDataURL('image/png');

            // 3. Crear promesas para cargar ambas imágenes
            const loadImage = (url) => {
                return new Promise((resolve, reject) => {
                    const img = new Image();
                    img.onload = () => resolve(img);
                    img.onerror = (e) => reject(e);
                    img.src = url;
                });
            };

            let pdfImage, konvaImage;
            try {
                 // Esperar a que ambas imágenes carguen
                 [pdfImage, konvaImage] = await Promise.all([
                     loadImage(pdfDataURL),
                     loadImage(konvaDataURL)
                 ]);
            } catch (error) {
                console.error('Error loading images for export:', error);
                alert('Error preparing images for export.');
                return;
            }


            // 4. Crear un canvas temporal para combinar
            var tempCanvas = document.createElement('canvas');
            tempCanvas.width = stage.width(); // Usar las dimensiones de la etapa Konva/canvas PDF
            tempCanvas.height = stage.height();
            var tempCtx = tempCanvas.getContext('2d');

            // 5. Dibujar la imagen del PDF y la imagen de Konva en el canvas temporal
            tempCtx.drawImage(pdfImage, 0, 0, tempCanvas.width, tempCanvas.height);
            tempCtx.drawImage(konvaImage, 0, 0, tempCanvas.width, tempCanvas.height);


            // 6. Obtener la imagen combinada como Data URL
            var combinedDataURL = tempCanvas.toDataURL('image/png');

            // 7. Enviar la imagen combinada al servidor usando fetch
            fetch('{{ route('export.image') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({
                    image_data: combinedDataURL,
                    filename: filename // Usamos el nombre del archivo PDF original
                }),
            })
            .then(response => {
                if (!response.ok) {
                     return response.json().then(err => {
                         throw new Error(err.error || `HTTP error! status: ${response.status}`);
                     }).catch(() => {
                         throw new Error(`HTTP error! status: ${response.status}`);
                     });
                }
                return response.json(); // Procesar si es OK
            })
            .then(data => {
                if (data.url) {
                    // Abrir la URL de la imagen guardada en una nueva pestaña
                    window.open(data.url, '_blank');
                } else {
                    alert('Error exporting image: No URL received from server.');
                }
            })
            .catch(error => {
                console.error('Export Fetch Error:', error);
                alert('Export failed: ' + error.message);
            });
        }

        // ==============================================================
        // MANEJADORES DE EVENTOS - Adjuntados via JavaScript (dentro del módulo)
        // ==============================================================

        // Manejar la subida del formulario
        document.getElementById('upload-form').addEventListener('submit', function(event) {
            event.preventDefault(); // Prevenir la subida estándar del formulario
            var formData = new FormData(this); // Obtener los datos del formulario

            // Usar fetch para enviar los datos al servidor (solicitud AJAX)
            fetch(this.action, {
                method: this.method,
                body: formData,
                headers: {
                    // Incluir el token CSRF para Laravel
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => {
                         throw new Error(err.error || `HTTP error! status: ${response.status}`);
                     }).catch(() => {
                         throw new Error(`HTTP error! status: ${response.status}`);
                     });
                }
                return response.json(); // Parsear la respuesta como JSON
            })
            .then(data => {
                // Si la respuesta JSON contiene pdfUrl y filename
                if (data.pdfUrl && data.filename) {
                    pdfUrl = data.pdfUrl; // Guardar la URL del PDF
                    filename = data.filename; // Guardar el nombre del archivo
                    loadPdf(pdfUrl); // Cargar el PDF
                     // Opcional: Ocultar la sección de carga después de subir
                     // document.getElementById('upload-section').style.display = 'none';
                } else {
                     // Esto se ejecuta si la respuesta es 200 OK pero no tiene las propiedades esperadas
                    alert('Upload successful but server response is invalid.');
                }
            })
            .catch(error => {
                // Este bloque se ejecuta si fetch falla por red o si los .then lanzan un error
                console.error('Upload Fetch Error:', error);
                alert('Upload failed: ' + error.message);
            });
        });

        // CORRECCIÓN: Adjuntar event listener al botón "Add Text"
        // Asegúrate de que el ID 'add-text-button' exista en tu HTML
        var addTextButton = document.getElementById('add-text-button');
        if (addTextButton) {
            addTextButton.addEventListener('click', addText);
        } else {
            console.error("Button with ID 'add-text-button' not found.");
        }


        // CORRECCIÓN: Adjuntar event listener al input file para la imagen
        // Asegúrate de que el ID 'add-image-input' exista en tu HTML
        var addImageInput = document.getElementById('add-image-input');
        if (addImageInput) {
            addImageInput.addEventListener('change', function(event) {
                 if (event.target.files.length > 0) {
                    addImageFromFile(event.target.files[0]);
                    // Opcional: Limpiar el input file para permitir subir el mismo archivo de nuevo
                    event.target.value = '';
                 }
            });
        } else {
            console.error("Input with ID 'add-image-input' not found.");
        }


        // CORRECCIÓN: Adjuntar event listener al botón "Export Image"
        // Asegúrate de que el ID 'export-button' exista en tu HTML
        var exportButton = document.getElementById('export-button');
        if (exportButton) {
            exportButton.addEventListener('click', exportCanvas);
        } else {
            console.error("Button with ID 'export-button' not found.");
        }


        // ==============================================================
        // Lógica inicial al cargar la página (Opcional)
        // ==============================================================
         // Esta parte solo se ejecuta si el controlador carga la vista pasando $pdfUrl y $filename
         // Si solo usas la subida AJAX, esta parte no hará nada hasta que la subida ocurra.
        @if(isset($pdfUrl) && isset($filename))
            pdfUrl = "{{ $pdfUrl }}";
            filename = "{{ $filename }}";
            loadPdf(pdfUrl);
        @endif


    </script>

</body>
</html>