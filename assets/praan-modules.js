'use strict';
// Require an explicit confirmation for disabling/beta-scoping a module.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form[data-pm-danger]').forEach(form => {
    form.addEventListener('submit', event => {
      const enabled = form.querySelector('[name="enabled"]')?.value;
      const mode = form.querySelector('[name="mode"]')?.value;
      if (enabled === '0' || mode === 'sandbox') {
        const message = enabled === '0' ? 'এই Module বন্ধ করবেন? এর মেনু ও URL বন্ধ হবে, সংরক্ষিত তথ্য মুছবে না।' : 'Sandbox Mode-এ শুধুমাত্র Super Admin ফিচারটি দেখতে পাবেন। নিশ্চিত?';
        if (!window.confirm(message)) event.preventDefault();
      }
    });
  });
});
