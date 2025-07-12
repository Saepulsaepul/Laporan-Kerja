// Salin semua kode dari dalam tag <script>...</script> di bawah ke sini
document.addEventListener('DOMContentLoaded', function () {
    const imagePreviewModal = document.getElementById('imagePreviewModal');
    if (imagePreviewModal) {
        imagePreviewModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const imageUrl = button.getAttribute('data-image-url');
            const imageTitle = button.getAttribute('data-image-title');
            
            const modalTitle = imagePreviewModal.querySelector('.modal-title');
            const modalImage = imagePreviewModal.querySelector('#modalImage');
            
            modalTitle.textContent = imageTitle;
            modalImage.src = imageUrl;
        });
    }
});