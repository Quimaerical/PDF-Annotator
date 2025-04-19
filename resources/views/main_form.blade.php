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
        
        import { getDocument, GlobalWorkerOptions } from 'https://unpkg.com/pdfjs-dist@5.1.91/build/pdf.min.mjs';
        

        // ==============================================================
        // VARIABLES DE ESTADO - Ahora definidas en el ámbito del módulo
        // ==============================================================

        GlobalWorkerOptions.workerSrc = 'https://unpkg.com/pdfjs-dist@5.1.91/build/pdf.worker.min.mjs';

        var pdfUrl = null; // URL from loaded pdf
        var pdfDoc = null; // Object PDFDocumentProxy de PDF.js
        var pageNum = 1;   // actual page (to change page to Annotate change this var
        var pageRendering = false; // a rendering page
        var pageNumPending = null; // number of pending page if another is requested during render
        var pdfCanvas = document.getElementById('pdf-canvas'); // Elemento canvas del PDF
        var pdfCtx = pdfCanvas.getContext('2d'); // Contexto 2D del canvas del PDF
        var filename = null; // Name of PDF file 

        var width, height; // size of Konva canvas 
        var stage = null; //  Konva stage
        var layer = null; // Konva layer


        // ==============================================================
        // FUNCIONES PRINCIPALES - Definidas en el ámbito del módulo
        // ==============================================================

        
        function renderPage(num) {
            pageRendering = true;
            if (layer) {
                layer.destroyChildren();
                layer.draw(); // upload konva layer if exists
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
                    layer.draw(); 
                });
            });
        }

        function loadPdf(url) {
            
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

        
        function addText() {
            var textInput = document.getElementById('add-text-input');
            var textValue = textInput.value;
            if (textValue && stage && layer) {
                var textNode = new Konva.Text({
                    x: 50,
                    y: 50,
                    text: textValue,
                    fontSize: 20,
                    fill: '#000000', 
                    draggable: true,
                });
                layer.add(textNode);
                layer.draw();
                textInput.value = ''; // clean the input after adding
            }
        }

        /**
        * Adds an Image to Konva from a file
        * @param {File} file El archivo de imagen seleccionado.
        */
        function addImageFromFile(file) {
            if (file && stage && layer) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var imageObj = new Image();
                    imageObj.onload = function() {
                        var originalWidth = imageObj.width;
                        var originalHeight = imageObj.height;
                        var maxInitialSize = 150;
                        var newWidth = originalWidth;
                        var newHeight = originalHeight;
                        if (originalWidth > maxInitialSize || originalHeight > maxInitialSize){
                            var aspectRatio = originalWidth / originalHeight;

                            if (originalWidth > originalHeight){
                                newWidth = maxInitialSize;
                                newHeight = maxInitialSize / aspectRatio;
                            }else{
                                newHeight = maxInitialSize;
                                newWidth = maxInitialSize * aspectRatio;
                            }
                        }
                        var imageNode = new Konva.Image({
                            x: 50,
                            y: 50,
                            image: imageObj,
                            width: newWidth,
                            height: newHeight,
                            draggable: true,
                            
                        });
                        layer.add(imageNode);
                        layer.draw();

                        imageNode.on('click tap', function () {
                            layer.find('Transformer').destroy(); // destroy actual transformers
                            var transformer = new Konva.Transformer({
                                nodes: [imageNode],
                            });
                            layer.add(transformer);
                            layer.draw();
                        });
                         
                        stage.on('click tap', function (e) {
                            // if click/tap wasnt in a konva shape remove transformers
                            if (e.target === stage) {
                                layer.find('Transformer').destroy();
                                layer.draw();
                            }
                        });
                    };
                    imageObj.onerror = function() {
                        console.error("Error loading image for Konva", file.name);
                        alert("Could not load image: " + file.name);
                    };
                    imageObj.src = e.target.result;
                };
                reader.readAsDataURL(file); 
            }
        }

        /*
        Combine the PDF canvas and Konva workflow and export as an image.
        Then send the image to the server for saving.
        */
        async function exportCanvas() {
            if (!pdfDoc || !stage || !layer || !filename) {
                alert('PDF not loaded or something went wrong.');
                return;
            }

            layer.draw(); // Update konva

            // 1. Obtain Data URL from konva layer
            var konvaDataURL = layer.toDataURL();

            // 2. obtain Data URL from PDF canvas
            var pdfDataURL = pdfCanvas.toDataURL('image/png');

            // 3. new Promises for both images
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
                 // wait for both images to load 
                 [pdfImage, konvaImage] = await Promise.all([
                     loadImage(pdfDataURL),
                     loadImage(konvaDataURL)
                 ]);
            } catch (error) {
                console.error('Error loading images for export:', error);
                alert('Error preparing images for export.');
                return;
            }


            // 4. create a temp canvas to combine
            var tempCanvas = document.createElement('canvas');
            tempCanvas.width = stage.width(); 
            tempCanvas.height = stage.height();
            var tempCtx = tempCanvas.getContext('2d');

            // 5. draw PDF image and konva image in the temporary canvas
            tempCtx.drawImage(pdfImage, 0, 0, tempCanvas.width, tempCanvas.height);
            tempCtx.drawImage(konvaImage, 0, 0, tempCanvas.width, tempCanvas.height);


            // 6. find combine image as data URL
            var combinedDataURL = tempCanvas.toDataURL('image/png');

            // 7. send combined image to server using fetch
            fetch('{{ route('export.image') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({
                    image_data: combinedDataURL,
                    filename: filename // original pdf name
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
                return response.json(); // Process if ok
            })
            .then(data => {
                if (data.url) {
                    // open saved image in a new tab to allow user saving as image or pdf
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

        // Handle form
        document.getElementById('upload-form').addEventListener('submit', function(event) {
            event.preventDefault(); // prevent default form upload
            var formData = new FormData(this); // Obtain form data

            // Use fetch to send data to server (AJAX)
            fetch(this.action, {
                method: this.method,
                body: formData,
                headers: {
                    // inlcude CSRF token for Laravel
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
                return response.json(); // Parse JSON response
            })
            .then(data => {
                // fi JSON response contains pdfUrl and filename
                if (data.pdfUrl && data.filename) {
                    pdfUrl = data.pdfUrl; // save pdf url
                    filename = data.filename; // save filename
                    loadPdf(pdfUrl); 
                    
                } else {
                    // Alert if response is 200 OK but wrong properties
                    alert('Upload successful but server response is invalid.');
                }
            })
            .catch(error => {
                // if fetch fails bcs network or .then throw error
                console.error('Upload Fetch Error:', error);
                alert('Upload failed: ' + error.message);
            });
        });

        // add listener to add-text-button
        var addTextButton = document.getElementById('add-text-button');
        if (addTextButton) {
            addTextButton.addEventListener('click', addText);
        } else {
            console.error("Button with ID 'add-text-button' not found.");
        }


        // add listener to add-image-input
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


        // add listener to export-button
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