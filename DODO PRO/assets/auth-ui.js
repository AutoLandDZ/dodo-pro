(()=>{
  document.querySelectorAll('[data-password-toggle]').forEach(button=>{
    const input=document.getElementById(button.dataset.passwordToggle||'');
    if(!(input instanceof HTMLInputElement))return;
    button.addEventListener('click',()=>{
      const show=input.type==='password';input.type=show?'text':'password';button.setAttribute('aria-pressed',String(show));button.setAttribute('aria-label',show?'إخفاء كلمة المرور':'إظهار كلمة المرور');button.textContent=show?'◉':'◉';
    });
  });
})();
