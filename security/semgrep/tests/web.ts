declare const value: string;
declare const element: HTMLElement;
declare const sanitizer: {bypassSecurityTrustHtml(value: string): unknown; sanitize(value: string): unknown};

// ruleid: gam-web-html-trust
sanitizer.bypassSecurityTrustHtml(value);
// ok: gam-web-html-trust
sanitizer.sanitize(value);

// ruleid: gam-web-dynamic-html
element.innerHTML = value;
// ok: gam-web-dynamic-html
element.textContent = value;

// ruleid: gam-web-dynamic-eval
eval(value);
// ok: gam-web-dynamic-eval
JSON.parse(value);

// ruleid: gam-web-document-write
document.write(value);
// ok: gam-web-document-write
element.textContent = value;

// ruleid: gam-web-dangerous-url
sanitizer.bypassSecurityTrustUrl(value);
// ok: gam-web-dangerous-url
sanitizer.sanitize(value);

// ruleid: gam-web-token-storage
localStorage.setItem("access_token", value);
// ok: gam-web-token-storage
localStorage.setItem("theme", value);
