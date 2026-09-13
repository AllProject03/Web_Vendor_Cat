function togglePassword() {

    const password =
        document.getElementById("password");

    const icon =
        document.getElementById("passwordIcon");


    if (password.type === "password") {

        password.type = "text";

        icon.classList.remove("bi-eye-slash");

        icon.classList.add("bi-eye");

    } else {

        password.type = "password";

        icon.classList.remove("bi-eye");

        icon.classList.add("bi-eye-slash");

    }

}