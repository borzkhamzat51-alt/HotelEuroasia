<style>
#propEditOverlay {
    display: none; position: fixed; inset: 0;
    background: rgba(22,50,79,.52);
    backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px);
    z-index: 9000; align-items: center; justify-content: center; padding: 20px;
}
#propEditOverlay.is-open { display: flex; }
#propEditModal {
    background: #fff; border-radius: 18px; max-width: 480px; width: 100%;
    padding: 36px 32px 28px; box-shadow: 0 32px 80px -16px rgba(0,0,0,.32);
    position: relative; font-family: 'Inter', sans-serif;
    animation: propModalIn 220ms cubic-bezier(.3,0,.2,1);
}
@keyframes propModalIn {
    from { opacity:0; transform:translateY(16px) scale(.97); }
    to   { opacity:1; transform:none; }
}
.pm-close {
    position: absolute; top: 14px; right: 16px; background: none; border: none;
    font-size: 1.5rem; color: #5b7693; cursor: pointer; line-height: 1;
    padding: 4px 8px; border-radius: 8px; transition: background 120ms;
}
.pm-close:hover { background: #eef5fc; color: #16324f; }
.pm-title {
    font-family: 'Playfair Display', serif; font-size: 1.35rem;
    font-weight: 700; color: #16324f; margin: 0 0 3px;
}
.pm-subtitle { font-size: .78rem; color: #5b7693; margin: 0 0 24px; }
.pm-field { margin-bottom: 18px; }
.pm-label {
    display: block; font-size: .72rem; font-weight: 700; color: #2c4a68;
    letter-spacing: .04em; text-transform: uppercase; margin-bottom: 7px;
}
.pm-input {
    width: 100%; padding: 10px 13px; border: 1.5px solid #c5deef;
    border-radius: 9px; font-family: inherit; font-size: .88rem;
    color: #16324f; box-sizing: border-box; outline: none;
    transition: border-color 140ms, box-shadow 140ms;
}
.pm-input:focus { border-color: #3b7dd8; box-shadow: 0 0 0 3px rgba(59,125,216,.14); }
#propImgPreviewWrap { display: none; margin-bottom: 12px; }
#propImgPreview {
  width: 100%;
  height: 130px;
  object-fit: contain;
  object-position: center;
  border-radius: 10px;
  border: 1px solid #c5deef;
  background: #ffffff;
}
.pm-img-hint { font-size: .72rem; color: #5b7693; margin: 5px 0 0; }
.pm-or {
    display: flex; align-items: center; gap: 10px;
    margin: 12px 0 10px; color: #8dafc8; font-size: .72rem;
}
.pm-or::before,.pm-or::after { content:''; flex:1; height:1px; background:#d9eaf6; }
.pm-file-label {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px; border: 1.5px dashed #8dafc8; border-radius: 9px;
    font-size: .8rem; color: #2c4a68; cursor: pointer;
    transition: border-color 140ms, background 140ms;
}
.pm-file-label:hover { border-color: #3b7dd8; background: #f0f7ff; }
.pm-file-input { display: none; }
#propEditError {
    display: none; color: #b3433f; font-size: .8rem;
    background: #fdf2f2; border: 1px solid #f5c6c6;
    border-radius: 8px; padding: 9px 12px; margin-bottom: 16px;
}
.pm-actions {
    display: flex; gap: 10px; justify-content: flex-end;
    margin-top: 26px; padding-top: 20px; border-top: 1px solid #e8f1fa;
}
.pm-btn {
    padding: 10px 22px; border-radius: 9px; font-family: inherit;
    font-weight: 600; font-size: .86rem; cursor: pointer;
    transition: background 140ms, box-shadow 140ms, opacity 140ms;
}
.pm-btn--cancel { background: #eef5fc; color: #2c4a68; border: 1.5px solid #c5deef; }
.pm-btn--cancel:hover { background: #deeaf7; }
.pm-btn--save {
    background: #3b7dd8; color: #fff; border: none;
    box-shadow: 0 4px 14px -4px rgba(59,125,216,.5);
}
.pm-btn--save:hover { background: #2e6abf; }
.pm-btn:disabled { opacity: .55; cursor: not-allowed; }
</style>