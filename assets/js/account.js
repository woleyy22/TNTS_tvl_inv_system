(function () {
    'use strict';
    const birthday = document.getElementById('profileBirthday');
    const age = document.getElementById('profileAge');
    function updateAge() {
        if (!birthday.value || !birthday.validity.valid) { age.value = ''; return; }
        const [y, m, d] = birthday.value.split('-').map(Number);
        // PHP supplies today's date in the school's configured timezone.
        const [todayY, todayM, todayD] = birthday.max.split('-').map(Number);
        age.value = todayY - y - ((todayM < m || (todayM === m && todayD < d)) ? 1 : 0);
    }
    birthday.addEventListener('input', updateAge);
    const file = document.getElementById('profilePhoto');
    const preview = document.getElementById('photoPreview');
    const remove = document.getElementById('removePhoto');
    const error = document.getElementById('photoError');
    const initialPreview = preview.innerHTML;
    let previewUrl = null;
    function revokePreview() { if (previewUrl) { URL.revokeObjectURL(previewUrl); previewUrl = null; } }
    file.addEventListener('change', function () {
        revokePreview(); file.setCustomValidity(''); error.hidden = true; preview.innerHTML = initialPreview;
        const photo = file.files[0];
        if (!photo) return;
        if (photo.size > 2 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(photo.type)) {
            error.textContent = 'Choose a JPG, PNG or WebP image no larger than 2 MB.'; error.hidden = false;
            file.setCustomValidity(error.textContent); return;
        }
        if (remove) remove.checked = false;
        previewUrl = URL.createObjectURL(photo);
        const img = document.createElement('img'); img.src = previewUrl; img.alt = 'Selected profile picture preview'; img.className = 'profile-avatar-large';
        preview.replaceChildren(img);
    });
    if (remove) remove.addEventListener('change', function () {
        revokePreview(); file.value = ''; file.setCustomValidity(''); error.hidden = true;
        if (!remove.checked) { preview.innerHTML = initialPreview; return; }
        const fallback = document.createElement('span'); fallback.className = 'profile-avatar-large';
        fallback.textContent = Array.from(document.getElementById('profileName').value.trim())[0] || '?';
        preview.replaceChildren(fallback);
    });
    window.addEventListener('pagehide', revokePreview);
    const newPassword = document.getElementById('newPassword');
    const confirmPassword = document.getElementById('confirmPassword');
    function validatePassword() {
        newPassword.setCustomValidity(new TextEncoder().encode(newPassword.value).length > 72 ? 'This password is too long. Please use a shorter passphrase.' : '');
        confirmPassword.setCustomValidity(confirmPassword.value && confirmPassword.value !== newPassword.value ? 'The new passwords do not match.' : '');
    }
    newPassword.addEventListener('input', validatePassword); confirmPassword.addEventListener('input', validatePassword);
    document.getElementById('showPasswords').addEventListener('change', function () {
        document.querySelectorAll('#passwordForm input[autocomplete]').forEach(input => { input.type = this.checked ? 'text' : 'password'; });
    });
})();
