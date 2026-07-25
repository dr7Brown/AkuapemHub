function togglePasswordField(inputId, show) {
    var input = document.getElementById(inputId);
    if (!input) return;
    input.type = show ? 'text' : 'password';
}
