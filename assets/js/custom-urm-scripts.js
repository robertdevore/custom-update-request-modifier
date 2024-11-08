document.addEventListener( "DOMContentLoaded", function() {
    // Function to open modal
    function openModal(modalId) {
        var modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = "block";
        }
    }

    // Function to close modal
    function closeModal(modalId) {
        var modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = "none";
        }
    }

    // Add event listeners to Show buttons
    var showButtons = document.querySelectorAll(".show-modal");
    showButtons.forEach(function(button) {
        button.addEventListener("click", function() {
            var modalId = this.getAttribute("data-modal");
            openModal(modalId);
        });
    });

    // Add event listeners to Close buttons
    var closeButtons = document.querySelectorAll(".custom-urm-close");
    closeButtons.forEach(function(span) {
        span.addEventListener("click", function() {
            var modalId = this.getAttribute("data-modal");
            closeModal(modalId);
        });
    });

    // Add event listener to close modal when clicking outside the modal content
    window.addEventListener("click", function(event) {
        if (event.target.classList.contains("custom-urm-modal")) {
            event.target.style.display = "none";
        }
    });
});
