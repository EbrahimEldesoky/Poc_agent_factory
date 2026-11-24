const form = document.getElementById('login') as HTMLFormElement;
const err = document.getElementById('err') as HTMLParagraphElement;

form.addEventListener('submit', async (e: SubmitEvent) => {
  e.preventDefault();
  const f = new FormData(form);
  err.hidden = true;
  const r = await fetch('/api/login', {
    method: 'POST',
    headers: {'content-type': 'application/json'},
    body: JSON.stringify({email: f.get('email'), password: f.get('password')}),
  });
  if (r.ok) {
    location.href = '/';
  } else {
    const data = (await r.json().catch(() => ({}))) as {detail?: string};
    err.textContent = data.detail ?? 'Sign in failed';
    err.hidden = false;
  }
});
