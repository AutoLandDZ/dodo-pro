(() => {
  const nav=document.getElementById('mainNav'),menu=document.getElementById('menuToggle');
  menu?.addEventListener('click',()=>{const open=nav.classList.toggle('open');menu.setAttribute('aria-expanded',String(open));});
  nav?.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>{nav.classList.remove('open');menu?.setAttribute('aria-expanded','false');}));

  const slides=[...document.querySelectorAll('.slide')],dots=[...document.querySelectorAll('[data-slide-to]')];
  if(slides.length){let current=0,timer;const show=index=>{current=(index+slides.length)%slides.length;slides.forEach((slide,i)=>slide.classList.toggle('active',i===current));dots.forEach((dot,i)=>dot.classList.toggle('active',i===current));};const restart=()=>{clearInterval(timer);timer=setInterval(()=>show(current+1),6500);};
    dots.forEach((dot,index)=>dot.addEventListener('click',()=>{show(index);restart();}));
    document.querySelector('.slider-prev')?.addEventListener('click',()=>{show(current-1);restart();});
    document.querySelector('.slider-next')?.addEventListener('click',()=>{show(current+1);restart();});
    document.querySelector('.hero-slider')?.addEventListener('mouseenter',()=>clearInterval(timer));
    document.querySelector('.hero-slider')?.addEventListener('mouseleave',restart);restart();}

  const key='autoland_cart_v1';let cart=[];
  try{cart=JSON.parse(localStorage.getItem(key)||'[]');if(!Array.isArray(cart))cart=[];}catch{cart=[];}
  const cartBox=document.getElementById('cartItems'),status=document.getElementById('formStatus');
  const save=()=>localStorage.setItem(key,JSON.stringify(cart));
  const render=()=>{
    if(!cartBox)return;
    if(!cart.length){cartBox.innerHTML='<p class="muted">سلة الطلب فارغة — أضف منتجاً من المتجر.</p>';return;}
    cartBox.replaceChildren();
    cart.forEach((item,index)=>{
      const row=document.createElement('div');row.className='cart-line';
      const title=document.createElement('span');title.textContent=item.name;
      const minus=document.createElement('button');minus.type='button';minus.textContent='−';minus.setAttribute('aria-label','تقليل الكمية');minus.onclick=()=>{if(item.quantity<=1)cart.splice(index,1);else item.quantity--;save();render();};
      const quantity=document.createElement('span');quantity.textContent=String(item.quantity);
      const plus=document.createElement('button');plus.type='button';plus.textContent='+';plus.setAttribute('aria-label','زيادة الكمية');plus.onclick=()=>{if(item.quantity<10)item.quantity++;save();render();};
      row.append(title,minus,quantity,plus);cartBox.append(row);
    });
  };
  document.querySelectorAll('.add-cart').forEach(button=>{
    const details=document.createElement('a');details.className='product-detail-link';details.href=`product.php?id=${encodeURIComponent(button.dataset.id||'')}`;details.textContent=document.documentElement.lang==='en'?'Details':document.documentElement.lang==='fr'?'Détails':'تفاصيل';button.before(details);
    button.addEventListener('click',()=>{
    const id=Number(button.dataset.id),found=cart.find(item=>item.id===id);
    if(found){if(found.quantity<10)found.quantity++;}else cart.push({id,name:button.dataset.name,quantity:1});
    save();render();if(status){status.textContent='أُضيف المنتج إلى الطلب. أكمل بياناتك لإرساله.';status.className='form-status success';}
    document.getElementById('contact')?.scrollIntoView({behavior:'smooth',block:'center'});
    });
  });
  render();
  document.getElementById('orderForm')?.addEventListener('submit',async event=>{
    event.preventDefault();if(!cart.length){status.textContent='أضف منتجاً واحداً على الأقل أولاً.';status.className='form-status error';return;}
    const form=event.currentTarget,button=form.querySelector('[type=submit]');button.disabled=true;button.textContent='جارٍ تسجيل الطلب…';status.textContent='';
    let waWindow=null;
    try{
      const body=new FormData(form);body.set('cart',JSON.stringify(cart));
      const response=await fetch(window.AUTO_CONFIG.orderEndpoint,{method:'POST',body,headers:{'Accept':'application/json'}});const data=await response.json();
      if(!response.ok||!data.ok)throw new Error(data.message||'تعذر إرسال الطلب.');
      cart=[];save();render();form.reset();status.textContent=`تم تسجيل طلبك رقم ${data.code}. سيفتح واتساب لتأكيد التفاصيل.`;status.className='form-status success';
      waWindow=window.open(data.whatsapp,'_blank','noopener');if(!waWindow)status.innerHTML=`تم تسجيل طلبك رقم <b>${data.code}</b>. <a href="${data.whatsapp}" target="_blank" rel="noopener">اضغط هنا لفتح واتساب</a>.`;
    }catch(error){if(waWindow)waWindow.close();status.textContent=error.message||'حدث خطأ. حاول مجدداً.';status.className='form-status error';}
    finally{button.disabled=false;button.textContent='إرسال الطلب عبر واتساب ←';}
  });
})();
