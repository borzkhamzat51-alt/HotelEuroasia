<!-- ── Property card edit modal ──────────────────────────────────────── -->
<div id="propEditOverlay" role="dialog" aria-modal="true" aria-labelledby="propModalTitle">
    <div id="propEditModal">
        <button class="pm-close" onclick="closePropEdit()" aria-label="Close">&#x2715;</button>

        <h2 class="pm-title" id="propModalTitle">Edit Property Card</h2>
        <p class="pm-subtitle" id="propModalSub"></p>

        <form id="propEditForm" enctype="multipart/form-data">
            <input type="hidden" name="key" id="propKey">

            <div class="pm-field">
                <label class="pm-label" for="propName">Property Name</label>
                <input class="pm-input" type="text" name="name" id="propName"
                       placeholder="e.g. BB Apartelle" required>
            </div>

            <div class="pm-field">
                <label class="pm-label" for="propDesc">Description / Tagline</label>
                <input class="pm-input" type="text" name="description" id="propDesc"
                       placeholder="e.g. Comfort & Style">
            </div>

            <div class="pm-field">
                <label class="pm-label">Card Image</label>

                <div id="propImgPreviewWrap">
                    <img id="propImgPreview" src="" alt="Current image">
                    <p class="pm-img-hint">Current image — upload or paste a URL below to replace it.</p>
                </div>

                <label class="pm-file-label" for="propImage">
                    <svg viewBox="0 0 24 24" fill="none" width="14" height="14" aria-hidden="true">
                        <path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M12 4v12M8 8l4-4 4 4"
                              stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Upload image
                    <input class="pm-file-input" type="file" id="propImage" name="image"
                           accept="image/*" onchange="previewPropImage(this)">
                </label>

                <div class="pm-or">or</div>

                <label class="pm-label" for="propImageUrl"
                       style="text-transform:none;letter-spacing:0;">Paste an image URL</label>
                <input class="pm-input" type="url" id="propImageUrl" name="image_url"
                       placeholder="https://example.com/photo.jpg">
            </div>

            <div id="propEditError"></div>

            <div class="pm-actions">
                <button type="button" class="pm-btn pm-btn--cancel" onclick="closePropEdit()">Cancel</button>
                <button type="submit" class="pm-btn pm-btn--save" id="propSaveBtn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('propEditOverlay');

    window.openPropertyEdit = function (prop) {
        document.getElementById('propKey').value      = prop.key;
        document.getElementById('propName').value     = prop.name;
        document.getElementById('propDesc').value     = prop.tag;
        document.getElementById('propImageUrl').value = '';
        document.getElementById('propImage').value    = '';
        document.getElementById('propModalSub').textContent = 'Editing: ' + prop.name;
        document.getElementById('propEditError').style.display = 'none';

        var wrap = document.getElementById('propImgPreviewWrap');
        if (prop.image) {
            document.getElementById('propImgPreview').src = prop.image;
            wrap.style.display = 'block';
        } else {
            wrap.style.display = 'none';
        }

        overlay.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        document.getElementById('propName').focus();
    };

    window.closePropEdit = function () {
        overlay.classList.remove('is-open');
        document.body.style.overflow = '';
    };

    window.previewPropImage = function (input) {
        if (!input.files || !input.files[0]) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            document.getElementById('propImgPreview').src = e.target.result;
            document.getElementById('propImgPreviewWrap').style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    };

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closePropEdit();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closePropEdit();
    });

    document.getElementById('propEditForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('propSaveBtn');
        var err = document.getElementById('propEditError');
        err.style.display = 'none';
        btn.textContent = 'Saving…';
        btn.disabled = true;

        fetch('process_property_settings.php',  {
            method: 'POST',
            body: new FormData(this),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                location.reload();
            } else {
                err.textContent = data.message || 'Could not save. Please try again.';
                err.style.display = 'block';
                btn.textContent = 'Save Changes';
                btn.disabled = false;
            }
        })
        .catch(function () {
            err.textContent = 'Network error. Please try again.';
            err.style.display = 'block';
            btn.textContent = 'Save Changes';
            btn.disabled = false;
        });
    });
}());
</script>