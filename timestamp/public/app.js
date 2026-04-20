// app.js – burger + delete & edit modals
document.addEventListener('DOMContentLoaded', () => {
  const burger = document.querySelector('.burger');
  const links = document.querySelector('.nav-links');
  if (burger && links) burger.addEventListener('click', () => links.classList.toggle('open'));

  // Helpers
  const show = el => el && (el.style.display = 'flex');
  const hide = el => el && (el.style.display = 'none');

  // DELETE modal
  const delBackdrop = document.getElementById('tsDelBackdrop');
  const delForm = document.getElementById('tsDelForm');
  const delId = document.getElementById('tsDelId');
  const delDate = document.getElementById('tsDelDate');
  document.body.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-delete-id]');
    if (btn) {
      e.preventDefault();
      delId.value = btn.getAttribute('data-delete-id');
      delDate.value = btn.getAttribute('data-delete-date');
      show(delBackdrop);
    }
    if (e.target?.hasAttribute?.('data-close') || e.target === delBackdrop) hide(delBackdrop);
  });
  window.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(delBackdrop); });

  // EDIT modal
  const editBackdrop = document.getElementById('tsEditBackdrop');
  const editForm = document.getElementById('tsEditForm');
  const editId = document.getElementById('tsEditId');
  const editDate = document.getElementById('tsEditDate');
  const editProject = document.getElementById('tsEditProject');
  const editHours = document.getElementById('tsEditHours');
  const editNote = document.getElementById('tsEditNote');

  document.body.addEventListener('click', (e) => {
    const el = e.target.closest('[data-edit-id]');
    if (el) {
      e.preventDefault();
      editId.value = el.getAttribute('data-edit-id');
      editDate.value = el.getAttribute('data-edit-date');
      editProject.value = el.getAttribute('data-edit-project');
      editHours.value = el.getAttribute('data-edit-hours');
      editNote.value = el.getAttribute('data-edit-note') || '';
      show(editBackdrop);
    }
    if (e.target?.hasAttribute?.('data-close') || e.target === editBackdrop) hide(editBackdrop);
  });
  window.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(editBackdrop); });
});
