document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('foto_bukti');
    const imagePreviewContainer = document.getElementById('imagePreview');
    const previewImage = document.getElementById('preview');
    const cameraModalEl = document.getElementById('cameraModal');
    const cameraModal = new bootstrap.Modal(cameraModalEl);
    const btnAmbilFoto = document.getElementById('btnAmbilFoto');
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const btnJepret = document.getElementById('btnJepret');
    const btnGunakanFoto = document.getElementById('btnGunakanFoto');
    const btnAmbilUlang = document.getElementById('btnAmbilUlang');
    const btnSwitchCamera = document.getElementById('btnSwitchCamera');
    
    let videoStream = null;
    let currentFacingMode = 'environment';

    function displayPreview(file) {
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImage.src = e.target.result;
                imagePreviewContainer.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    }

    async function startCamera() {
        if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop());
        }
        video.style.transform = currentFacingMode === 'user' ? 'scaleX(-1)' : 'scaleX(1)';
        const constraints = { video: { facingMode: currentFacingMode }, audio: false };
        try {
            videoStream = await navigator.mediaDevices.getUserMedia(constraints);
            video.srcObject = videoStream;
            video.style.display = 'block';
            canvas.classList.add('d-none');
            btnJepret.style.display = 'inline-block';
            btnGunakanFoto.style.display = 'none';
            btnAmbilUlang.style.display = 'none';
            btnSwitchCamera.style.display = 'inline-block';
        } catch (err) {
            cameraModal.hide();
            alert('Gagal mengakses kamera. Pastikan Anda memberikan izin pada browser dan mencoba lagi.');
        }
    }

    function takePicture() {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const context = canvas.getContext('2d');
        context.save();
        if (currentFacingMode === 'user') {
            context.translate(canvas.width, 0);
            context.scale(-1, 1);
        }
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        context.restore();
        video.style.display = 'none';
        canvas.classList.remove('d-none');
        btnJepret.style.display = 'none';
        btnGunakanFoto.style.display = 'inline-block';
        btnAmbilUlang.style.display = 'inline-block';
        btnSwitchCamera.style.display = 'none';
    }

    function useCapturedPhoto() {
        canvas.toBlob(function(blob) {
            const file = new File([blob], 'capture.jpg', { type: 'image/jpeg' });
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            fileInput.files = dataTransfer.files;
            displayPreview(file);
            cameraModal.hide();
        }, 'image/jpeg', 0.9);
    }

    function switchCamera() {
        currentFacingMode = currentFacingMode === 'environment' ? 'user' : 'environment';
        startCamera();
    }

    fileInput.addEventListener('change', (e) => displayPreview(e.target.files[0]));
    btnAmbilFoto.addEventListener('click', () => cameraModal.show());
    cameraModalEl.addEventListener('shown.bs.modal', startCamera);
    cameraModalEl.addEventListener('hidden.bs.modal', () => {
        if (videoStream) videoStream.getTracks().forEach(track => track.stop());
    });
    btnJepret.addEventListener('click', takePicture);
    btnAmbilUlang.addEventListener('click', startCamera);
    btnGunakanFoto.addEventListener('click', useCapturedPhoto);
    btnSwitchCamera.addEventListener('click', switchCamera);
});