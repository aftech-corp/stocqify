<?php
/**
 * Reusable WordPress-style image uploader component.
 *
 * Call renderImageUploader() to get the HTML.
 * Call imageUploaderAssets() once per page to emit the shared CSS + JS.
 *
 * @param string $id              Unique identifier (used as HTML id prefix)
 * @param string $inputName       <input type="file"> name attribute
 * @param string $label           Label shown above the uploader
 * @param string $existingUrl     Full URL of current image (empty = none)
 * @param string $removeFieldName Name for the hidden remove flag (empty = no remove button)
 * @param string $height          CSS height of the uploader area
 */
function renderImageUploader(
    string $id,
    string $inputName,
    string $label       = 'Image',
    string $existingUrl = '',
    string $removeFieldName = '',
    string $height      = '180px'
): string {
    $hasExisting  = (bool)$existingUrl;
    $emptyDisplay = $hasExisting ? 'none' : 'flex';
    $prevDisplay  = $hasExisting ? 'block' : 'none';
    $imgSrc       = $hasExisting ? h($existingUrl) : '';

    $removeFld = '';
    if ($removeFieldName) {
        $removeFld = '<input type="hidden" id="rm-' . h($id) . '" name="' . h($removeFieldName) . '" value="0">';
    }

    return <<<HTML
<div class="img-uploader-wrap">
    <label class="block text-sm font-medium text-gray-700 mb-2">{$label}</label>
    <div class="img-upload-zone" id="uploader-{$id}" style="height:{$height}"
         onclick="imgUploaderTrigger('{$id}')"
         ondragover="imgUploaderDragOver(event, '{$id}')"
         ondragleave="imgUploaderDragLeave('{$id}')"
         ondrop="imgUploaderDrop(event, '{$id}')">
        <input type="file" id="file-{$id}" name="{$inputName}"
               accept="image/jpeg,image/png,image/gif,image/webp"
               style="display:none"
               onchange="imgUploaderPreview('{$id}', this)">

        <!-- Empty / placeholder state -->
        <div id="empty-{$id}" class="img-upload-empty" style="display:{$emptyDisplay}">
            <div class="img-upload-icon">
                <i class="fa-solid fa-image"></i>
            </div>
            <p class="img-upload-title">Click to upload</p>
            <p class="img-upload-hint">or drag &amp; drop here</p>
            <p class="img-upload-meta">PNG, JPG, WEBP &mdash; max 2 MB</p>
        </div>

        <!-- Preview state -->
        <div id="preview-{$id}" class="img-upload-preview" style="display:{$prevDisplay}">
            <img id="img-{$id}" src="{$imgSrc}" alt="Preview">
            <div class="img-upload-overlay">
                <i class="fa-solid fa-arrow-up-from-bracket mr-1"></i> Change image
            </div>
            <button type="button" class="img-upload-remove"
                    onclick="imgUploaderClear(event, '{$id}', '{$removeFieldName}')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>
    {$removeFld}
</div>
HTML;
}

/**
 * Emit shared CSS + JS for the image uploader (call once per page, before closing </body>).
 * Uses a static flag so it's safe to call multiple times.
 */
function imageUploaderAssets(): void {
    static $emitted = false;
    if ($emitted) return;
    $emitted = true;
    echo <<<'ASSETS'
<style>
.img-uploader-wrap { margin-bottom: 0; }

.img-upload-zone {
    border: 2px dashed #d1d5db;
    border-radius: 12px;
    background: #f9fafb;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: border-color .2s, background .2s;
    width: 100%;
}
.img-upload-zone:hover,
.img-upload-zone.drag-over {
    border-color: #3b82f6;
    background: #eff6ff;
}
.img-upload-zone.drag-over::after {
    content: 'Drop to upload';
    position: absolute;
    inset: 0;
    background: rgba(59,130,246,.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    color: #1d4ed8;
    z-index: 20;
    pointer-events: none;
}

/* Empty / placeholder */
.img-upload-empty {
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 28px 20px;
    gap: 4px;
    width: 100%;
    height: 100%;
}
.img-upload-icon {
    width: 52px; height: 52px;
    background: #e5e7eb;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
    color: #9ca3af;
    font-size: 22px;
    transition: background .2s, color .2s;
}
.img-upload-zone:hover .img-upload-icon {
    background: #dbeafe;
    color: #3b82f6;
}
.img-upload-title {
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    margin: 0;
}
.img-upload-hint {
    font-size: 12px;
    color: #6b7280;
    margin: 2px 0 0;
}
.img-upload-meta {
    font-size: 11px;
    color: #9ca3af;
    margin: 4px 0 0;
}

/* Preview */
.img-upload-preview {
    position: absolute;
    inset: 0;
}
.img-upload-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.img-upload-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,.45);
    display: none;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    pointer-events: none;
}
.img-upload-preview:hover .img-upload-overlay {
    display: flex;
}
.img-upload-remove {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 30px; height: 30px;
    background: rgba(0,0,0,.55);
    color: #fff;
    border: none;
    border-radius: 50%;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 15;
    transition: background .15s;
    pointer-events: all;
}
.img-upload-remove:hover { background: rgba(220,38,38,.9); }
</style>
<script>
function imgUploaderTrigger(id) {
    document.getElementById('file-' + id).click();
}
function imgUploaderPreview(id, input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('img-' + id).src = e.target.result;
        document.getElementById('empty-' + id).style.display = 'none';
        document.getElementById('preview-' + id).style.display = 'block';
        const rm = document.getElementById('rm-' + id);
        if (rm) rm.value = '0';
    };
    reader.readAsDataURL(input.files[0]);
}
function imgUploaderClear(evt, id, removeField) {
    evt.stopPropagation();
    document.getElementById('file-' + id).value = '';
    document.getElementById('img-' + id).src = '';
    document.getElementById('empty-' + id).style.display = 'flex';
    document.getElementById('preview-' + id).style.display = 'none';
    const rm = document.getElementById('rm-' + id);
    if (rm) rm.value = '1';
}
function imgUploaderDragOver(evt, id) {
    evt.preventDefault();
    document.getElementById('uploader-' + id).classList.add('drag-over');
}
function imgUploaderDragLeave(id) {
    document.getElementById('uploader-' + id).classList.remove('drag-over');
}
function imgUploaderDrop(evt, id) {
    evt.preventDefault();
    const zone = document.getElementById('uploader-' + id);
    zone.classList.remove('drag-over');
    const files = evt.dataTransfer.files;
    if (!files.length) return;
    const input = document.getElementById('file-' + id);
    try {
        const dt = new DataTransfer();
        dt.items.add(files[0]);
        input.files = dt.files;
    } catch(e) { return; }
    imgUploaderPreview(id, input);
}
</script>
ASSETS;
}
