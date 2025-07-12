<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Script sederhana untuk menandai link sidebar yang aktif secara dinamis
        document.addEventListener("DOMContentLoaded", function() {
            const currentPath = window.location.pathname.split("/").pop();
            const sidebarLinks = document.querySelectorAll("#sidebarMenu .nav-link");
            
            sidebarLinks.forEach(link => {
                if (link.getAttribute("href") === currentPath) {
                    link.classList.add("active");
                }
            });
        });
    </script>
    <div class="modal fade" id="changeCredentialsModal" tabindex="-1" aria-labelledby="changeCredentialsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="changeCredentialsModalLabel"><i class="fas fa-shield-alt me-2"></i>Ubah Username & Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="changeCredentialsForm">
                    <div id="modal-error-alert" class="alert alert-danger d-none" role="alert"></div>

                    <div class="mb-3">
                        <label for="new_username" class="form-label">Username Baru</label>
                        <input type="text" class="form-control" id="new_username" name="new_username" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_password" class="form-label">Password Baru</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                        <div class="form-text">Minimal 8 karakter.</div>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Konfirmasi Password Baru</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                    <div class="modal-footer pb-0 px-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary" id="save-credentials-btn">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
// Gabungkan script baru dengan event listener yang sudah ada
document.addEventListener('DOMContentLoaded', function() {
    // Script yang sudah ada untuk sidebar
    const currentPath = window.location.pathname.split("/").pop();
    const sidebarLinks = document.querySelectorAll("#sidebarMenu .nav-link");
    sidebarLinks.forEach(link => {
        if (link.getAttribute("href") === currentPath) {
            link.classList.add("active");
        }
    });

    // Script BARU untuk form modal
    const form = document.getElementById('changeCredentialsForm');
    const modalErrorAlert = document.getElementById('modal-error-alert');
    const saveButton = document.getElementById('save-credentials-btn');
    
    if(form) { // Pastikan form ada di halaman ini
        form.addEventListener('submit', function(event) {
            event.preventDefault(); // Mencegah form submit secara normal

            modalErrorAlert.classList.add('d-none');
            saveButton.disabled = true;
            saveButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Menyimpan...';

            const formData = new FormData(form);
            const newPassword = formData.get('new_password');
            const confirmPassword = formData.get('confirm_password');

            if (newPassword.length < 8) {
                showError('Password baru harus minimal 8 karakter.');
                return;
            }
            if (newPassword !== confirmPassword) {
                showError('Password baru dan konfirmasi tidak cocok.');
                return;
            }

            fetch('ajax_update_admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message); 
                    window.location.href = '../login.php'; 
                } else {
                    showError(data.message || 'Terjadi kesalahan.');
                }
            })
            .catch(error => {
                showError('Tidak dapat terhubung ke server. Coba lagi.');
            });
        });
    }

    function showError(message) {
        modalErrorAlert.textContent = message;
        modalErrorAlert.classList.remove('d-none');
        saveButton.disabled = false;
        saveButton.innerHTML = 'Simpan Perubahan';
    }

    const changeCredentialsModal = document.getElementById('changeCredentialsModal');
    if(changeCredentialsModal) { // Pastikan modal ada
        changeCredentialsModal.addEventListener('hidden.bs.modal', function () {
          form.reset();
          modalErrorAlert.classList.add('d-none');
          saveButton.disabled = false;
          saveButton.innerHTML = 'Simpan Perubahan';
        });
    }
});
</script>
</body>
</html>