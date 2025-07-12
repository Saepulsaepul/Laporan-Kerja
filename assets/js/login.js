// Pindahkan semua kode dari dalam tag <script>...</script> ke file ini
document.addEventListener("DOMContentLoaded", function () {
  function setupPasswordToggle(toggleId, passwordId) {
    const toggleButton = document.getElementById(toggleId);
    const passwordInput = document.getElementById(passwordId);
    if (!toggleButton || !passwordInput) return;

    toggleButton.addEventListener("click", function () {
      const icon = this.querySelector("i");
      const isPassword = passwordInput.type === "password";
      passwordInput.type = isPassword ? "text" : "password";
      icon.classList.toggle("fa-eye", !isPassword);
      icon.classList.toggle("fa-eye-slash", isPassword);
    });
  }

  setupPasswordToggle("toggleUserPassword", "user_password");
  setupPasswordToggle("toggleAdminPassword", "admin_password");
});
