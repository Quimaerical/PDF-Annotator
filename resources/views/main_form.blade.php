<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PDF Annotation</title>
    <script src="https://unpkg.com/konva@9/konva.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" integrity="sha384-SgOJa3DmI69IUzQ2PVdRZhwQ+dy64/BUtbMJw1MZ8t5HZApcHrRKUc4W0kG879m7" crossorigin="anonymous">
    <link rel="stylesheet" href="{{ asset('css/normalize.css') }}">
    <link rel="stylesheet" href="{{ asset('css/main.css') }}">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&icon_names=contrast" />

</head>
<body>
    <div id="container" class="container d-flex flex-column align-items-center">
        <div id="top-section" class="container d-flex justify-content-end top-section"> 
            <div class="mx-5"></div> {{-- spacing to center flex --}}
            <div class="mx-auto">
                <h2 class="align-self-center p-2">PDF Annotator</h2>
            </div>
            
            <div class="align-self-center m-3">
                <button id="darkMode" type="button" class="btn d-flex align-items-center">
                    <span class="material-symbols-outlined">contrast</span>
                </button>
            </div>
        </div>
        <div id="upload-section" class="d-flex flex-column border-bottom border-primary-subtle m-3">
            <h3>Upload PDF</h3>

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul>
                        @foreach ($errors->all() as $error )
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            
            <div class="d-flex justify-content-center mt-3">
                <form id="upload-form" action="{{ route('upload.pdf') }}" method="POST" enctype="multipart/form-data" class="d-flex flex-column">
                    @csrf
                    <label for="pdf_file" class="btn">Select PDF</label>
                    <input type="file" name="pdf_file" id="pdf_file" required>
                    <button type="submit" class="btn my-3">Upload</button>
                    
                </form>
            </div>
        </div>

        <div id="annotation-section" class="container d-flex flex-column align-items-center mt-5">
            <h3>Annotate PDF</h3>
            <div id="pdf-container">
                <canvas id="pdf-canvas"></canvas>
                <div id="konva-container"></div>
            </div>
            <div class="control-item m-auto">
                    <button id="editMode" class="btn m-3">Edit</button>
            </div>

            <div class="controls d-flex justify-content-evenly">
                <div class="control-item d-flex">
                    
                    <input type="text" id="add-text-input" class="my-2" style="color:#f0f0f0;">
                    
                    <button id="add-text-button" class="btn align-self-end m-3">Add Text</button>
                </div>

                <div class="control-item align-self-center">
                    <label for="add-image-input">Add Image</label>
                    <input type="file" id="add-image-input" accept="image/*">
                </div> 
            </div>

            <div class="control-item">
                <button id="export-button" class="btn mt-3">Export Image</button>
            </div>
        </div>
    </div>

    <script type="module">
        // ==============================================================
        // MODULE IMPORTS
        // ==============================================================

        import { getDocument, GlobalWorkerOptions } from 'https://unpkg.com/pdfjs-dist@5.1.91/build/pdf.min.mjs';

        // ==============================================================
        // STATE VARIABLES - Now defined within the module scope
        // ==============================================================

        GlobalWorkerOptions.workerSrc = 'https://unpkg.com/pdfjs-dist@5.1.91/build/pdf.worker.min.mjs';

        const toggleButton = document.getElementById('darkMode'); // gets dark mode button as const
        const body = document.body; // gets body to manipulate class
        const darkModeStorageKey = 'darkModeEnabled'; // stores preference in server
        let pdfUrl = null; // URL from loaded pdf
        let pdfDoc = null; // PDFDocumentProxy object from PDF.js
        let pageNum = 1;     // current page (to change page to Annotate, change this var)
        let pageRendering = false; // a rendering page
        let pageNumPending = null; // number of pending page if another is requested during render
        let pdfCanvas = document.getElementById('pdf-canvas'); // PDF canvas element
        let pdfCtx = pdfCanvas.getContext('2d'); // 2D context of the PDF canvas
        let filename = null; // Name of PDF file
        // Add state variable
        let isEditModeActive = false;
        let width, height; // size of Konva canvas
        let stage = null;   // Konva stage
        let layer = null;   // Konva layer

        // Variable para mantener una referencia al transformador actual
        let currentTransformer = null;


        // ==============================================================
        // MAIN FUNCTIONS - Defined within the module scope
        // ==============================================================

        function applyMode(isDarkMode) {
            if (isDarkMode) {
                body.classList.add('dark-mode');
            } else {
                body.classList.remove('dark-mode');
            }
        }
        const savedMode = localStorage.getItem(darkModeStorageKey);
        if (savedMode === 'true') {
            applyMode(true);
        } else {
            // if there's no preference clear mode prefered
            applyMode(false);
        }
        toggleButton.addEventListener('click', () => {
            const isDarkMode = body.classList.contains('dark-mode');
            // Alternate mode
            applyMode(!isDarkMode);
            // store the preference in localStorage
            localStorage.setItem(darkModeStorageKey, !isDarkMode);
        });

        function renderPage(num) {
            pageRendering = true;

            // Clear existing annotations and transformers when changing page
            if (layer) {
                layer.destroyChildren(); // Destroy shapes in the layer
                layer.draw();
                // Also destroy the transformer if it exists and clear its reference
                if (currentTransformer) { 
                    currentTransformer.destroy(); 
                    currentTransformer = null; 
                }
            }

            pdfDoc.getPage(num).then((page) => {
                const viewport = page.getViewport({ scale: 1.5 });
                pdfCanvas.height = viewport.height;
                pdfCanvas.width = viewport.width;

                width = pdfCanvas.width;
                height = pdfCanvas.height;

                const konvaContainer = document.getElementById('konva-container');
                
                if (!stage) {
                    stage = new Konva.Stage({
                        container: konvaContainer,
                        width: width,
                        height: height,
                    });
                    layer = new Konva.Layer();
                    stage.add(layer);

                    // Add global click listener to remove transformers when clicking outside shapes
                    // =========================================================================
                    // MODIFIED STAGE CLICK LISTENER: Handles selection when Edit Mode is active
                    // =========================================================================
                    stage.on('click tap', (e) => { // <--- Correcto, 'e' está definido aquí
                        // Check if the click was on the transformer itself
                        const clickedOnTransformer = e.target.getParent().className === 'Transformer'; // <-- Esta línea SÍ está bien AQUÍ

                        // Deselect if click is on stage background OR on the transformer itself
                        // Only deselect logic happens here now, AND it happens on stage OR transformer click
                        if (e.target === stage || clickedOnTransformer) {
                            // If a transformer is currently attached...
                            if (currentTransformer) {
                                // ...destroy it and clear the reference (deselect)
                                currentTransformer.destroy();
                                currentTransformer = null; // Clear the reference
                                layer.draw(); // Redraw to remove the transformer
                            }
                            return; // Stop here if click was on stage background or transformer
                        }

                        // If the click target is a shape (not stage or transformer)
                        // Only perform selection logic if Edit Mode is active
                        if (isEditModeActive) {
                            // Deselect any other selected nodes by removing the old transformer
                            stage.find('Transformer').forEach(tr => tr.destroy()); // <--- Usar forEach
                            currentTransformer = null; // Clear the reference

                            // Create new transformer and attach it to the clicked shape
                            const transformer = new Konva.Transformer({
                                nodes: [e.target], // Attach to the clicked shape
                                anchorSize: 8, // size of corner anchors
                                borderEnabled: true,
                                borderStroke: 'yellow', // color of the border
                                keepRatio: e.target instanceof Konva.Image, // Maintain aspect ratio only for images
                                enabledAnchors: ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'bottom', 'top', 'left', 'right'],
                            });
                            layer.add(transformer);
                            currentTransformer = transformer; // Store reference to the current transformer

                            layer.draw(); // Redraw the layer to show the transformer

                        }
                        // If not in Edit Mode, clicking on a shape only performs dragging (handled by draggable: true)
                        // and clicking elsewhere does nothing.
                    });

                    
                    // Add keydown listener for deletion
                    // document.addEventListener('keydown', (e) => {
                    //     // Check if a shape is selected (i.e., a transformer exists and is attached)
                    //     if (currentTransformer && (e.key === 'Delete' || e.key === 'Backspace')) {
                    //         // Prevent default browser behavior (like navigating back)
                    //         e.preventDefault();

                    //         // Get the node the transformer is attached to
                    //         const selectedNodes = currentTransformer.getNodes();

                    //         if (selectedNodes.length > 0) {
                    //             const nodeToRemove = selectedNodes[0]; // Assuming only one node selected at a time

                    //             // Remove the transformer first
                    //             currentTransformer.destroy();
                    //             currentTransformer = null; // Clear reference

                    //             // Remove the node (shape)
                    //             nodeToRemove.destroy();

                    //             // Redraw the layer
                    //             layer.draw();
                    //             console.log('Shape deleted.');
                    //         }
                    //     }
                    // });
                    document.addEventListener('keydown', (e) => {
                    // Check if Edit Mode is active AND a shape is selected (transformer exists)
                    // AND the pressed key is Delete or Backspace
                    if (isEditModeActive && currentTransformer && (e.key === 'Delete' || e.key === 'Backspace')) { // <--- MODIFIED CONDITION
                        // Prevent default browser behavior (like navigating back)
                        e.preventDefault();

                        // Get the node the transformer is attached to
                        const selectedNodes = currentTransformer.getNodes();

                        if (selectedNodes.length > 0) {
                            const nodeToRemove = selectedNodes[0]; // Assuming only one node selected at a time

                            // Remove the transformer first
                            currentTransformer.destroy();
                            currentTransformer = null; // Clear reference

                            // Remove the node (shape)
                            nodeToRemove.destroy();

                            // Redraw the layer
                            layer.draw();
                            console.log('Shape deleted.');
                        }
                    }
                });

                } else {
                    stage.width(width);
                    stage.height(height);
                }
                konvaContainer.style.width = width + 'px';
                konvaContainer.style.height = height + 'px';


                const renderContext = {
                    canvasContext: pdfCtx,
                    viewport: viewport,
                };

                const renderTask = page.render(renderContext);

                renderTask.promise.then(() => {
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
            getDocument(url).promise.then((pdf) => {
                pdfDoc = pdf;
                document.getElementById('pdf-canvas').style.display = 'block';
                document.getElementById('annotation-section').style.display = 'block';
                renderPage(pageNum);
            }).catch((error) => {
                console.error('Error loading PDF:', error);
                alert('Error loading PDF after upload. Check console for details.');
                document.getElementById('annotation-section').style.display = 'none';
            });
        }

        function addText() {
            const textInput = document.getElementById('add-text-input');
            const textValue = textInput.value;

            if (textValue && stage && layer) {
                const textNode = new Konva.Text({
                    x: 50,
                    y: 50,
                    text: textValue,
                    fontSize: 20,
                    fill: '#000000',
                    draggable: true,
                });

                layer.add(textNode);
                layer.draw();
                textInput.value = ''; // Clear the input after adding

                 // Add click listener to the text node to attach a transformer
                textNode.on('click tap', (e) => {
                    // The global stage listener will handle attaching the transformer
                    // Just make sure this click doesn't propagate to the stage if not needed
                    // e.cancelBubble = true; // Uncomment if clicks on text shouldn't deselect others
                });
            }
        }

        /*
        * Adds an Image to Konva from a file.
        * Maintains aspect ratio of the image upon adding,
        * scaling if necessary to fit within an initial maximum size.
        * @param {File} file The selected image file.
        */
        function addImageFromFile(file) {
            if (file && stage && layer) {
                const reader = new FileReader();

                reader.onload = (e) => {
                    const imageObj = new Image();

                    imageObj.onload = () => {
                        const originalWidth = imageObj.width;
                        const originalHeight = imageObj.height;

                        // Define a maximum size for the initial image in the canvas
                        const maxInitialSize = 150; // Longest side won't exceed 150px

                        let newWidth = originalWidth;
                        let newHeight = originalHeight;

                        // Calculate new dimensions preserving aspect ratio
                        // if either side exceeds the maximum initial size
                        if (originalWidth > maxInitialSize || originalHeight > maxInitialSize) {
                            const aspectRatio = originalWidth / originalHeight;

                            if (originalWidth > originalHeight) {
                                // If width is greater, scale based on max width
                                newWidth = maxInitialSize;
                                newHeight = maxInitialSize / aspectRatio;
                            } else {
                                // If height is greater or equal, scale based on max height
                                newHeight = maxInitialSize;
                                newWidth = maxInitialSize * aspectRatio;
                            }
                        }

                        const imageNode = new Konva.Image({
                            x: 50,
                            y: 50,
                            image: imageObj,
                            width: newWidth,
                            height: newHeight,
                            draggable: true,
                        });

                        layer.add(imageNode);
                        layer.draw();

                        // Add click listener to the image node to attach a transformer
                        imageNode.on('click tap', (e) => {
                            // The global stage listener will handle attaching the transformer
                            // e.cancelBubble = true; // Uncomment if clicks on image shouldn't deselect others
                        });
                    };

                    imageObj.onerror = () => {
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

            // Hide the transformer during export
            if (currentTransformer) {
                currentTransformer.hide();
                layer.draw(); // Redraw the layer without the transformer
            }


            layer.draw(); // Ensure Konva is updated

            // 1. Obtain Data URL from Konva layer
            const konvaDataURL = layer.toDataURL();

            // 2. Obtain Data URL from PDF canvas
            const pdfDataURL = pdfCanvas.toDataURL('image/png');

            // 3. Create Promises for both images
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
                // Wait for both images to load
                [pdfImage, konvaImage] = await Promise.all([
                    loadImage(pdfDataURL),
                    loadImage(konvaDataURL),
                ]);
            } catch (error) {
                console.error('Error loading images for export:', error);
                alert('Error preparing images for export.');
                return;
            }

            // 4. Create a temp canvas to combine
            const tempCanvas = document.createElement('canvas');
            tempCanvas.width = stage.width();
            tempCanvas.height = stage.height();
            const tempCtx = tempCanvas.getContext('2d');

            // 5. Draw PDF image and Konva image in the temporary canvas
            tempCtx.drawImage(pdfImage, 0, 0, tempCanvas.width, tempCanvas.height);
            tempCtx.drawImage(konvaImage, 0, 0, tempCanvas.width, tempCanvas.height);

            // 6. Get combined image as Data URL
            const combinedDataURL = tempCanvas.toDataURL('image/png');

             // Show the transformer again after getting the Data URL
            if (currentTransformer) {
                currentTransformer.show();
                layer.draw(); // Redraw the layer with the transformer shown
            }


            // 7. Send combined image to server using fetch
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
        // EVENT HANDLERS - Attached via JavaScript (within the module)
        // ==============================================================

        // Handle form upload
        document.getElementById('upload-form').addEventListener('submit', function(event) {
            event.preventDefault(); // prevent default form upload
            const formData = new FormData(this); // Obtain form data

            // Use fetch to send data to server (AJAX)
            fetch(this.action, {
                method: this.method,
                body: formData,
                headers: {
                    // include CSRF token for Laravel
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
                // if JSON response contains pdfUrl and filename
                if (data.pdfUrl && data.filename) {
                    pdfUrl = data.pdfUrl; // save pdf url
                    filename = data.filename; // save filename
                    pageNum = 1; // Reset to first page for new PDF
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

        // add listener to editMode button
        const editButton = document.getElementById('editMode');
        if (editButton) {
            editButton.addEventListener('click', () => {



                // If stage is not initialized yet, do nothing
                if (!stage || !layer) {
                    console.warn("Konva stage not ready yet.");
                    return;
                }

                // Toggle edit mode state
                isEditModeActive = !isEditModeActive;

                // Update button text
                editButton.textContent = isEditModeActive ? 'Edit ON' : 'Edit OFF';

                // If activating edit mode, find all shapes and attach ONE transformer to them
                if (isEditModeActive) {
                    const allShapes = layer.find('Text, Image'); // Find all text and image nodes

                    // Destroy any existing transformers first
                    stage.find('Transformer').forEach((transformer) => {
                        tranformer.destroy();
                    });
                    currentTransformer = null; // Clear reference

                    // If there are shapes, create ONE transformer and attach it to ALL of them
                    if (allShapes.length > 0) {
                        const transformer = new Konva.Transformer({
                            nodes: allShapes, // Attach to all found shapes
                            anchorSize: 8,
                            borderEnabled: true,
                            borderStroke: 'yellow',
                            // Keep ratio FALSE for multi-select is common, allows independent scaling/skewing
                            // Set to TRUE if you want all selected items to scale proportionally together
                            keepRatio: false,
                            enabledAnchors: ['top-left', 'top-right', 'bottom-left', 'bottom-right'],
                            // Optional: restrict multi-select transformation (e.g., no rotation, skew)
                            // rotateEnabled: false,
                            // skewEnabled: false,
                        });

                        layer.add(transformer); // Add the transformer to the layer
                        currentTransformer = transformer; // Store reference
                        layer.draw(); // Redraw layer to show transformer(s)

                        console.log(`Edit mode ON. Attached transformer to ${allShapes.length} shapes.`);

                    } else {
                        console.log("Edit mode ON, but no shapes to attach transformer to.");
                    }

                } else {
                    // If deactivating edit mode, destroy the current transformer
                    if (currentTransformer) {
                        currentTransformer.destroy();
                        currentTransformer = null; // Clear reference
                        layer.draw(); // Redraw layer to remove transformer
                        console.log("Edit mode OFF. Transformer removed.");
                    }
                    // When edit mode is OFF, clicking on shapes only allows dragging (handled by draggable: true)
                    // and clicking on stage/empty space does nothing (handled by stage listener when !isEditModeActive)
                }
            });
        } else {
            console.error("Button with ID 'editMode' not found.");
        }

        // add listener to add-text-button
        const addTextButton = document.getElementById('add-text-button');
        if (addTextButton) {
            addTextButton.addEventListener('click', addText);
        } else {
            console.error("Button with ID 'add-text-button' not found.");
        }

        // add listener to add-image-input (the hidden file input)
        const addImageInput = document.getElementById('add-image-input');
        if (addImageInput) {
            addImageInput.addEventListener('change', function(event) {
                if (event.target.files.length > 0) {
                    addImageFromFile(event.target.files[0]);
                    // Optional: Clear the file input to allow selecting the same file again
                    event.target.value = '';
                }
            });
        } else {
            console.error("Input with ID 'add-image-input' not found.");
        }

        // add listener to export-button
        const exportButton = document.getElementById('export-button');
        if (exportButton) {
            exportButton.addEventListener('click', exportCanvas);
        } else {
            console.error("Button with ID 'export-button' not found.");
        }


        // ==============================================================
        // INITIAL PAGE LOAD LOGIC (Optional)
        // ==============================================================
        // This part only runs if the controller loads the view passing $pdfUrl and $filename.
        // If you only use AJAX upload, this part won't do anything until upload occurs.
        @if(isset($pdfUrl) && isset($filename))
            pdfUrl = "{{ $pdfUrl }}";
            filename = "{{ $filename }}";
            loadPdf(pdfUrl);
        @endif

    </script>

</body>
</html>