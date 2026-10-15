import { useCallback, useEffect, useRef, useState } from 'react';
import { Activity, ArrowDownRight, ArrowRight, Check, CheckCircle2, Clock3, ExternalLink, History, ImagePlus, Link2, LoaderCircle, LockKeyhole, LogOut, Mail, MousePointer2, Plus, RefreshCw, Search, ShoppingBag, Sparkles, Trash2, UserRound, X } from 'lucide-react';

const API = import.meta.env.VITE_API_URL || '/api';
const intervals = [5, 15, 30];

async function request(path, options) {
  const response = await fetch(`${API}${path}`, { ...options, credentials: 'include', headers: { 'Content-Type': 'application/json', ...options?.headers } });
  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    const validationMessage = Object.values(payload.errors || {}).flat()[0];
    throw new Error(payload.error || payload.message || validationMessage || 'Ocurrió un error al consultar el servidor.');
  }
  return response.status === 204 ? null : response.json();
}
function timeLabel(date) {
  if (!date) return 'En espera';
  const diff = Math.max(0, Math.ceil((Date.parse(date) - Date.now()) / 60_000));
  return diff < 1 ? 'Ahora' : `En ${diff} min`;
}

function xpathFor(element, doc) {
  const literal = (value) => {
    if (!value.includes("'")) return `'${value}'`;
    if (!value.includes('"')) return `"${value}"`;
    return `concat(${value.split("'").map((part, index) => `${index ? `, "'", ` : ''}'${part}'`).join('')})`;
  };
  if (element.id) return `//*[@id=${literal(element.id)}]`;
  const segments = [];
  let node = element;
  while (node && node.nodeType === 1 && node !== doc.body) {
    const tag = node.tagName.toLowerCase();
    const stableAttribute = ['data-testid', 'itemprop', 'aria-label'].find((attribute) => node.hasAttribute(attribute));
    if (stableAttribute) {
      segments.unshift(`${tag}[@${stableAttribute}=${literal(node.getAttribute(stableAttribute))}]`);
    } else {
      const classes = [...node.classList].slice(0, 2);
      const predicate = classes.map((className) => `contains(concat(' ', normalize-space(@class), ' '), ${literal(` ${className} `)})`).join(' and ');
      const base = predicate ? `${tag}[${predicate}]` : tag;
      if (node.parentElement) {
        const siblings = [...node.parentElement.children].filter((sibling) => sibling.tagName === node.tagName && classes.every((className) => sibling.classList.contains(className)));
        segments.unshift(siblings.length > 1 ? `${base}[${siblings.indexOf(node) + 1}]` : base);
      } else segments.unshift(base);
    }
    if (stableAttribute) break;
    node = node.parentElement;
  }
  return `//body/${segments.join('/')}`;
}

function ProductReviewModal({ url, preview, onClose, onSave }) {
  const frameRef = useRef(null);
  const modeRef = useRef('name');
  const selectedRef = useRef({ name: null, price: null, images: [] });
  const cleanupRef = useRef(null);
  const [mode, setMode] = useState('name');
  const [previewMode, setPreviewMode] = useState('original');
  const [selected, setSelected] = useState({ name: null, price: null, images: [] });
  const [hoverInfo, setHoverInfo] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => { modeRef.current = mode; }, [mode]);
  useEffect(() => { selectedRef.current = selected; }, [selected]);
  useEffect(() => () => cleanupRef.current?.(), []);

  const bindInspector = () => {
    const doc = frameRef.current?.contentDocument;
    if (!doc) return;
    let highlighted = null;
    let previousOutline = '';
    const onMove = (event) => {
      const target = event.target;
      if (target?.nodeType !== 1 || target === doc.body || target === doc.documentElement) return;
      if (highlighted !== target) {
        if (highlighted) highlighted.style.outline = previousOutline;
        highlighted = target;
        previousOutline = target.style.outline;
        target.style.outline = '2px solid #2c6945';
      }
      setHoverInfo({ tag: target.tagName.toLowerCase(), id: target.id, className: typeof target.className === 'string' ? target.className.trim() : '', text: (target.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 100) });
    };
    const onClick = (event) => {
      const target = event.target?.nodeType === 1 ? event.target : null;
      if (!target || !modeRef.current) return;
      event.preventDefault(); event.stopPropagation();
      const image = target.closest('img');
      if (modeRef.current === 'images' && !image) return;
      const element = modeRef.current === 'images' && image ? image : target;
      const path = xpathFor(element, doc);
      const value = modeRef.current === 'images'
        ? (image?.currentSrc || image?.src || '')
        : (element.textContent || '').trim().replace(/\s+/g, ' ');
      if (!value) return;
      const field = modeRef.current;
      const next = field === 'images'
        ? { ...selectedRef.current, images: selectedRef.current.images.some((item) => item.path === path) ? selectedRef.current.images.filter((item) => item.path !== path) : [...selectedRef.current.images, { path, value }] }
        : { ...selectedRef.current, [field]: { path, value } };
      selectedRef.current = next; setSelected(next);
      if (highlighted) highlighted.style.outline = previousOutline;
      setMode(''); modeRef.current = '';
    };
    doc.addEventListener('mouseover', onMove, true);
    doc.addEventListener('click', onClick, true);
    cleanupRef.current?.();
    cleanupRef.current = () => { doc.removeEventListener('mouseover', onMove, true); doc.removeEventListener('click', onClick, true); if (highlighted) highlighted.style.outline = previousOutline; };
  };

  const save = async () => {
    if (!selected.name && !selected.price && selected.images.length === 0) {
      setError('Selecciona al menos un dato del producto en la vista previa.'); return;
    }
    setBusy(true); setError('');
    try {
      await onSave({
        previewToken: preview.previewToken,
        selectors: { name: selected.name?.path || '', price: selected.price?.path || '', images: selected.images.map((image) => image.path) },
        selected: { name: selected.name?.value || '', price: selected.price?.value || '', images: selected.images.map((image) => image.value) },
      });
    } catch (cause) { setError(cause.message); }
    finally { setBusy(false); }
  };

  const fields = [{ id: 'name', label: 'Nombre' }, { id: 'price', label: 'Precio' }, { id: 'images', label: 'Imagen' }];
  return <div className="review-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
    <section className="review-modal" role="dialog" aria-modal="true" aria-labelledby="review-title">
      <header className="review-header"><div><span className="review-kicker">REVISIÓN DEL PRODUCTO</span><h2 id="review-title">Selecciona los datos que quieres vigilar</h2><p title={url}>{url}</p></div><button className="review-close" onClick={onClose} aria-label="Cerrar"><X size={19}/></button></header>
      <div className="review-toolbar"><div className="review-instructions"><MousePointer2 size={17}/> {previewMode === 'original' ? 'Vista original de la página. Cambia a selección para elegir los datos.' : 'Elige un dato y después haz clic sobre ese elemento en la página.'}</div><div className="review-toolbar-actions"><div className="review-view-tabs"><button className={previewMode === 'original' ? 'selected' : ''} onClick={() => setPreviewMode('original')}>Vista original</button><button className={previewMode === 'select' ? 'selected' : ''} onClick={() => { setPreviewMode('select'); setMode('name'); }}>Seleccionar datos</button></div>{previewMode === 'select' && <div className="review-field-tabs">{fields.map((field) => <button key={field.id} className={mode === field.id ? 'selected' : ''} onClick={() => setMode(field.id)}>{field.id === 'images' ? <ImagePlus size={15}/> : <MousePointer2 size={15}/>} {field.label}</button>)}</div>}</div></div>
      <div className="review-body"><div className="review-preview">{previewMode === 'original' ? <article className="review-product-preview"><div className="review-product-image">{preview.details?.image ? <img src={preview.details.image} alt={preview.details.name || 'Imagen del producto'}/> : <ImagePlus size={34}/>}</div><div className="review-product-copy"><span className="review-product-source">{preview.details?.source || new URL(url).hostname}</span><h3>{preview.details?.name || 'No se detectó el nombre del producto'}</h3><strong className="review-product-price">{preview.details?.price || 'Precio no disponible'}</strong><p>{preview.details?.description || 'La tienda no proporcionó una descripción detectable para este producto.'}</p><a href={url} target="_blank" rel="noreferrer"><ExternalLink size={15}/> Abrir página del producto</a></div></article> : <iframe ref={frameRef} title="Vista de selección del producto" srcDoc={preview.html} sandbox="allow-same-origin" onLoad={bindInspector}/>}</div><aside className="review-side"><div className="inspector-card"><strong>Elemento bajo el cursor</strong>{hoverInfo ? <><code>&lt;{hoverInfo.tag}{hoverInfo.id ? ` id="${hoverInfo.id}"` : ''}{hoverInfo.className ? ` class="${hoverInfo.className}"` : ''}&gt;</code><p>{hoverInfo.text || 'Sin texto'}</p></> : <p>{previewMode === 'original' ? 'Vista del producto detectado. Abre “Seleccionar datos” para inspeccionar el contenido capturado.' : 'Pasa el cursor por la vista para inspeccionar una etiqueta, su clase y su contenido.'}</p>}</div><div className="selected-fields"><strong>Datos seleccionados</strong>{fields.map((field) => { const item = field.id === 'images' ? selected.images : selected[field.id] ? [selected[field.id]] : []; return <div className="selected-field" key={field.id}><span className={item.length ? 'field-check checked' : 'field-check'}>{item.length ? <Check size={12}/> : null}</span><div><b>{field.label}{item.length > 1 ? ` (${item.length})` : ''}</b>{item.length ? item.map((entry, index) => <small key={entry.path}>{field.id === 'images' ? `Imagen ${index + 1}: ` : ''}{entry.value.slice(0, 110)}</small>) : <small>{previewMode === 'original' ? 'Pendiente de seleccionar' : 'Haz clic en un elemento para elegirlo'}</small>}</div></div>; })}</div><div className="review-tip">La selección se guardará para usarla en cada revisión automática.</div></aside></div>
      {error && <div className="review-error" role="alert">{error}</div>}
      <footer className="review-footer"><span>Revisión cada {preview.intervalMinutes} minutos</span><div><button className="review-cancel" onClick={onClose}>Cancelar</button><button className="review-save" onClick={save} disabled={busy}>{busy ? <LoaderCircle size={16} className="spin"/> : <CheckCircle2 size={16}/>} {preview.productId ? 'Guardar selección' : 'Guardar producto'}</button></div></footer>
    </section>
  </div>;
}

function parsePrice(value) {
  if (!value) return null;
  let number = value.replace(/[^\d,.]/g, '');
  if (!number) return null;
  if (number.includes('.') && number.includes(',')) number = number.replace(/,/g, '');
  else if (number.includes(',')) number = /,\d{1,2}$/.test(number) ? number.replace(',', '.') : number.replace(/,/g, '');
  const parsed = Number(number);
  return Number.isFinite(parsed) ? parsed : null;
}

function PriceChart({ entries }) {
  const points = [...entries].reverse().map((entry) => parsePrice(entry.price)).filter((point) => point !== null);
  if (points.length < 2) return <div className="chart-empty">Se necesitan al menos dos precios válidos para dibujar la gráfica.</div>;
  const min = Math.min(...points); const max = Math.max(...points); const spread = max - min || 1;
  const coordinates = points.map((point, index) => `${(index / (points.length - 1)) * 100},${36 - ((point - min) / spread) * 30}`).join(' ');
  const rising = points.at(-1) > points[0];
  return <svg className={`price-chart ${rising ? 'price-up' : 'price-down'}`} viewBox="0 0 100 40" preserveAspectRatio="none" role="img" aria-label={`Gráfica del cambio de precio; ${rising ? 'el último precio es mayor' : 'el último precio es menor o igual'} que el inicial`}><polyline points={coordinates}/></svg>;
}

function AuthPage({ onAuthenticated }) {
  const [mode, setMode] = useState('login');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const registering = mode === 'register';
  async function submit(event) {
    event.preventDefault(); setError(''); setBusy(true);
    try {
      const result = await request(registering ? '/auth/register' : '/auth/login', {
        method: 'POST', body: JSON.stringify({ ...(registering ? { name } : {}), email, password }),
      });
      onAuthenticated(result.user);
    } catch (cause) { setError(cause.message); }
    finally { setBusy(false); }
  }
  return <main className="auth-page">
    <section className="auth-story"><a className="brand auth-brand" href="#"><span className="brand-mark"><ShoppingBag size={18}/></span><span>radar<span className="brand-dot">.</span></span></a><div className="story-content"><span className="story-pill"><span className="live-dot"/> TU RADAR DE COMPRAS</span><h1>Compra mejor.<br/><em>Sin perseguir</em><br/>los precios.</h1><p>Reúne los productos que te interesan y deja que Radar vigile cada cambio por ti.</p><div className="story-preview"><div className="preview-image"><ShoppingBag size={25}/></div><div className="preview-copy"><small>SEGUIMIENTO ACTIVO</small><strong>Precio bajo vigilancia</strong><span><i/> Próxima revisión en 5 min</span></div><div className="preview-bars"><i/><i/><i/><i/><i/><i/><i/><i/></div></div></div><div className="story-foot"><span>Tu próxima buena compra empieza aquí.</span><span>RADAR · 2026</span></div></section>
    <section className="auth-panel"><div className="auth-card"><div className="auth-mobile-brand"><span className="brand-mark"><ShoppingBag size={18}/></span><span>radar<span className="brand-dot">.</span></span></div><div className="auth-kicker">{registering ? 'EMPIEZA GRATIS' : 'QUÉ BUENO VERTE'}</div><h2>{registering ? 'Crea tu cuenta' : 'Bienvenido de vuelta'}</h2><p className="auth-subtitle">{registering ? 'Configura tu espacio personal en unos segundos.' : 'Inicia sesión para volver a tu radar de precios.'}</p>
      <form className="auth-form" onSubmit={submit}>
        {registering && <label className="auth-field"><span>Nombre</span><div><UserRound size={17}/><input autoComplete="name" placeholder="Cómo te llamas" value={name} onChange={(event) => setName(event.target.value)} minLength={2} maxLength={120} required/></div></label>}
        <label className="auth-field"><span>Correo electrónico</span><div><Mail size={17}/><input type="email" autoComplete="email" placeholder="tu@correo.com" value={email} onChange={(event) => setEmail(event.target.value)} required/></div></label>
        <label className="auth-field"><span>Contraseña</span><div><LockKeyhole size={17}/><input type="password" autoComplete={registering ? 'new-password' : 'current-password'} placeholder={registering ? 'Al menos 8 caracteres' : 'Tu contraseña'} value={password} onChange={(event) => setPassword(event.target.value)} minLength={registering ? 8 : undefined} required/></div></label>
        {error && <div className="auth-error" role="alert">{error}</div>}
        <button className="auth-submit" disabled={busy}>{busy ? <LoaderCircle className="spin" size={17}/> : <>{registering ? 'Crear cuenta' : 'Iniciar sesión'}<ArrowRight size={17}/></>}</button>
      </form>
      <div className="auth-switch">{registering ? '¿Ya tienes una cuenta?' : '¿Todavía no tienes cuenta?'} <button onClick={() => { setMode(registering ? 'login' : 'register'); setError(''); }}>{registering ? 'Inicia sesión' : 'Regístrate'}</button></div>
      <div className="auth-safe"><LockKeyhole size={13}/> Tus datos se guardan de forma segura.</div>
    </div><div className="auth-panel-foot">© 2026 Radar · Monitorea con inteligencia</div></section>
  </main>;
}

function App() {
  const [user, setUser] = useState(null);
  const [authLoading, setAuthLoading] = useState(true);
  const [products, setProducts] = useState([]);
  const [history, setHistory] = useState([]);
  const [historyProductId, setHistoryProductId] = useState('');
  const [view, setView] = useState('products');
  const [reviewPreview, setReviewPreview] = useState(null);
  const [url, setUrl] = useState('');
  const [intervalMinutes, setIntervalMinutes] = useState(5);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(null);
  const [filter, setFilter] = useState('');

  useEffect(() => {
    request('/auth/me').then((result) => setUser(result.user)).catch(() => {}).finally(() => setAuthLoading(false));
  }, []);

  const loadProducts = useCallback(async () => {
    try { setProducts(await request('/products')); setError(''); }
    catch (e) { setError(e.message); }
    finally { setLoading(false); }
  }, []);
  const loadHistory = useCallback(async () => {
    try { setHistory(await request('/history')); }
    catch (e) { setError(e.message); }
  }, []);
  useEffect(() => { if (!user) return; loadProducts(); const timer = setInterval(loadProducts, 5000); return () => clearInterval(timer); }, [loadProducts, user]);
  useEffect(() => { if (user && view === 'history') loadHistory(); }, [loadHistory, user, view]);
  useEffect(() => { if (!user || view !== 'history') return; const timer = setInterval(loadHistory, 15000); return () => clearInterval(timer); }, [loadHistory, user, view]);
  useEffect(() => { if (products.length && !products.some((product) => product.id === historyProductId)) setHistoryProductId(products[0].id); }, [historyProductId, products]);

  const logout = async () => {
    try { await request('/auth/logout', { method: 'POST' }); } finally { setUser(null); setProducts([]); }
  };

  const addProduct = async (event) => {
    event.preventDefault();
    setSubmitting(true); setError(''); setNotice(null);
    try {
      const result = await request('/products/preview', { method: 'POST', body: JSON.stringify({ url }) });
      setReviewPreview({ ...result, url, intervalMinutes });
    } catch (e) { setError(e.message); }
    finally { setSubmitting(false); }
  };
  const reopenProductReview = async (product) => {
    setSubmitting(true); setError(''); setNotice(null);
    try {
      const result = await request(`/products/${product.id}/preview`, { method: 'POST' });
      setReviewPreview({ ...result, url: product.url, intervalMinutes: product.intervalMinutes, productId: product.id });
    } catch (e) { setError(e.message); }
    finally { setSubmitting(false); }
  };
  const saveReviewedProduct = async (review) => {
    if (reviewPreview.productId) {
      const product = await request(`/products/${reviewPreview.productId}/review`, { method: 'PUT', body: JSON.stringify(review) });
      setProducts((items) => items.map((item) => item.id === product.id ? product : item));
      setReviewPreview(null); setNotice({ type: product.error ? 'warning' : 'success', text: product.error || 'Selección guardada. La imagen se usará en las próximas revisiones.' });
      await loadHistory();
      return;
    }
    const product = await request('/products', { method: 'POST', body: JSON.stringify({ url: reviewPreview.url, intervalMinutes: reviewPreview.intervalMinutes, ...review }) });
    setProducts((items) => [product, ...items]); setUrl(''); setReviewPreview(null); setView('products');
    setNotice({ type: product.extractionStatus === 'partial' ? 'warning' : 'success', text: product.robotMessage });
    await loadHistory();
  };
  const closeReview = async () => {
    const token = reviewPreview?.previewToken;
    setReviewPreview(null);
    if (token) { try { await request(`/products/preview/${token}`, { method: 'DELETE' }); } catch { /* The preview expires automatically. */ } }
  };
  const removeProduct = async (id) => {
    try { await request(`/products/${id}`, { method: 'DELETE' }); setProducts((items) => items.filter((item) => item.id !== id)); }
    catch (e) { setError(e.message); }
  };
  const refreshProduct = async (id) => {
    try {
      const updated = await request(`/products/${id}/refresh`, { method: 'POST' });
      setProducts((items) => items.map((product) => product.id === id ? updated : product));
      setError(''); setNotice({ type: updated.extractionStatus === 'partial' ? 'warning' : 'success', text: updated.robotMessage || 'Robot ejecutado. Los datos del producto se actualizaron.' });
      if (view === 'history') await loadHistory();
    }
    catch (e) { setError(e.message); }
  };

  const visible = products.filter((product) => `${product.name} ${product.source} ${product.url}`.toLowerCase().includes(filter.toLowerCase()));
  const activeCount = products.filter((product) => !product.error).length;

  if (authLoading) return <main className="auth-loading"><span className="brand-mark"><ShoppingBag size={18}/></span><LoaderCircle className="spin" size={19}/></main>;
  if (!user) return <AuthPage onAuthenticated={setUser}/>;

  return <div className="app-shell">
    <aside className="sidebar">
      <a className="brand" href="#"><span className="brand-mark"><ShoppingBag size={18} strokeWidth={2.2}/></span><span>radar<span className="brand-dot">.</span></span></a>
      <div className="nav-label">MENU PRINCIPAL</div>
      <button className={`nav-item ${view === 'products' ? 'active' : ''}`} onClick={() => setView('products')}><ShoppingBag size={17}/><span>Productos</span><span className="nav-count">{products.length}</span></button>
      <button className={`nav-item ${view === 'history' ? 'active' : ''}`} onClick={() => setView('history')}><History size={17}/><span>Historial</span></button>
      <div className="side-bottom"><div className="profile"><div className="avatar">{user.name.charAt(0).toUpperCase()}</div><div className="profile-copy"><strong>{user.name}</strong><small>{user.email}</small></div><button className="logout-button" onClick={logout} aria-label="Cerrar sesión" title="Cerrar sesión"><LogOut size={15}/></button></div></div>
    </aside>

    <main className="main-content">
      <div className="page-wrap">
        <section className="intro-row"><div><div className="eyebrow"><Sparkles size={13}/> INTELIGENCIA DE PRECIOS</div><h1>{view === 'products' ? 'Productos' : 'Historial de precios'}</h1><p className="subtitle">{view === 'products' ? 'Sigue los productos que te interesan. Nosotros vigilamos el resto.' : 'Revisa cada captura y cómo cambia el precio de tus productos.'}</p></div><div className="updated"><span className="update-indicator"/>{view === 'products' ? 'Actualizado automáticamente' : `${history.length} capturas guardadas`}</div></section>

        {view === 'products' && <section className="stats-grid" aria-label="Resumen">
          <div className="stat-card"><div className="stat-top"><span>Total de productos</span><span className="stat-icon lavender"><ShoppingBag size={17}/></span></div><div className="stat-bottom"><strong>{products.length}</strong><span>en seguimiento</span></div></div>
          <div className="stat-card"><div className="stat-top"><span>Monitoreo activo</span><span className="stat-icon mint"><Activity size={17}/></span></div><div className="stat-bottom"><strong>{activeCount}</strong><span>productos saludables</span></div></div>
          <div className="stat-card highlight"><div className="stat-top"><span>Última actividad</span><span className="stat-icon white"><Check size={17}/></span></div><div className="stat-bottom"><strong>{products.some(p => p.lastCheckedAt) ? 'En vivo' : 'Listo'}</strong><span>Sincronización automática</span></div><div className="sparkline"><i/><i/><i/><i/><i/><i/><i/><i/><i/><i/><i/><i/><i/><i/><i/><i/><i/><i/><i/><i/></div></div>
        </section>}

        {view === 'products' && <section className="add-panel"><div className="panel-heading"><div className="add-icon"><Link2 size={18}/></div><div><h2>Agregar un producto</h2><p>Pega el enlace de cualquier tienda en línea para comenzar.</p></div></div><form className="add-form" onSubmit={addProduct}><label className="url-input"><Link2 size={16}/><input type="url" placeholder="https://tienda.com/producto..." value={url} onChange={(event) => setUrl(event.target.value)} required/><span className="input-hint">URL DEL PRODUCTO</span></label><label className="interval-select"><Clock3 size={16}/><select value={intervalMinutes} onChange={(event) => setIntervalMinutes(Number(event.target.value))}>{intervals.map((minutes) => <option key={minutes} value={minutes}>Cada {minutes} minutos</option>)}</select></label><button className="primary-button" disabled={submitting}>{submitting ? <><LoaderCircle className="spin" size={16}/>Abriendo vista de revisión…</> : <><Plus size={17}/>Revisar producto</>}</button></form>{notice && <div className={`robot-notice ${notice.type}`} role="status"><Check size={15}/><span>{notice.text}</span></div>}<div className="interval-note"><span className="note-check"><Check size={11}/></span><span>Cada producto tiene su propio horario de revisión. Puedes elegir <b>5, 15 o 30 minutos.</b></span></div></section>}

        {view === 'products' && <section className="products-section" id="productos"><div className="section-heading"><div><h2>Tu catálogo</h2><p>{products.length ? `${products.length} producto${products.length === 1 ? '' : 's'} bajo vigilancia` : 'Tus productos guardados aparecerán aquí'}</p></div><label className="search-box"><Search size={16}/><input aria-label="Buscar producto" placeholder="Buscar producto..." value={filter} onChange={(event) => setFilter(event.target.value)}/><kbd>⌘ K</kbd></label></div>
          {error && <div className="error-banner">{error}</div>}
          {loading ? <div className="empty-state"><LoaderCircle className="spin"/><p>Cargando productos...</p></div> : visible.length ? <div className="product-grid">{visible.map((product) => <article className="product-card" key={product.id}><div className="product-visual">{product.image ? <img src={product.image} alt={product.name || 'Producto'} onError={(event) => { event.currentTarget.style.display = 'none'; }}/> : <div className="image-placeholder"><ShoppingBag size={27}/><span>Imagen del producto</span></div>}{product.error ? <button className="product-status status-error status-action" onClick={() => reopenProductReview(product)} title="Volver a seleccionar los datos"><i/>Revisar</button> : <span className={`product-status ${product.lastCheckedAt ? 'status-live' : 'status-pending'}`}><i/>{product.lastCheckedAt ? 'Monitoreando' : 'Consultando'}</span>}<button className="delete-button" aria-label="Eliminar producto" onClick={() => removeProduct(product.id)}><Trash2 size={15}/></button></div>{product.images?.length > 1 && <div className="product-image-strip">{product.images.slice(1, 5).map((image, index) => <img key={`${product.id}-${index}`} src={image} alt={`${product.name || 'Producto'} ${index + 2}`}/>)}</div>}<div className="product-info"><div className="product-source">{product.source || new URL(product.url).hostname}<a href={product.url} target="_blank" rel="noreferrer" aria-label="Abrir tienda"><ExternalLink size={13}/></a></div><h3>{product.name || 'Detectando producto…'}</h3><div className="price-row"><strong>{product.price || '—'}</strong>{product.price && <span className="price-change"><ArrowDownRight size={14}/> Vigilado</span>}</div>{product.error && <div className="product-error">{product.error}</div>}<div className="product-footer"><span className="frequency"><Clock3 size={13}/>Cada {product.intervalMinutes} min</span><span className="next-check">{timeLabel(product.nextCheckAt)}</span><button className="refresh-button" aria-label="Actualizar ahora" onClick={() => refreshProduct(product.id)}><RefreshCw size={14}/></button></div></div></article>)}</div> : <div className="empty-state"><div className="empty-icon"><Search size={22}/></div><strong>{products.length ? 'No encontramos resultados' : 'Tu radar está listo'}</strong><p>{products.length ? 'Prueba otra búsqueda.' : 'Agrega el enlace de un producto para empezar a monitorear su precio.'}</p></div>}
        </section>}
        {view === 'history' && <section className="history-section">
          <div className="history-product-picker"><label htmlFor="history-product">Producto</label><select id="history-product" value={historyProductId} onChange={(event) => setHistoryProductId(event.target.value)}>{products.map((product) => <option key={product.id} value={product.id}>{product.name || product.source || product.url}</option>)}</select></div>
          {error && <div className="error-banner">{error}</div>}
          {(() => { const selectedProduct = products.find((product) => product.id === historyProductId); const rows = history.filter((entry) => entry.productId === historyProductId); return selectedProduct ? <><div className="history-summary"><div className="history-product-image">{selectedProduct.image ? <img src={selectedProduct.image} alt=""/> : <ShoppingBag size={24}/>}</div><div><span>{selectedProduct.source}</span><h2>{selectedProduct.name || 'Producto en seguimiento'}</h2><strong>{selectedProduct.price || 'Precio pendiente'}</strong></div><span className="frequency"><Clock3 size={14}/>Cada {selectedProduct.intervalMinutes} min</span></div><div className="history-chart-card"><div><h3>Movimiento del precio</h3><p>{rows.length} revisiones registradas</p></div><PriceChart entries={rows}/></div><div className="history-table-wrap"><table className="history-table"><thead><tr><th>Producto</th><th>Precio registrado</th><th>Fecha y hora</th><th>Frecuencia</th></tr></thead><tbody>{rows.map((entry) => { let images = []; try { images = JSON.parse(entry.image || '[]'); } catch { images = entry.image ? [entry.image] : []; } if (!Array.isArray(images)) images = entry.image ? [entry.image] : []; return <tr key={entry.id}><td><div className="history-row-product">{images[0] ? <img src={images[0]} alt=""/> : <ShoppingBag size={20}/>}<span>{entry.name || selectedProduct.name}</span></div></td><td className="history-row-price">{entry.price || 'Sin precio'}</td><td>{new Date(entry.capturedAt).toLocaleString('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}</td><td>Cada {selectedProduct.intervalMinutes} min</td></tr>; })}</tbody></table>{rows.length === 0 && <div className="empty-state"><History size={24}/><strong>Aún no hay capturas</strong><p>El historial aparecerá cuando se guarde la primera consulta del producto.</p></div>}</div></> : <div className="empty-state"><History size={24}/><strong>No hay productos para mostrar</strong><p>Agrega un producto primero; aquí aparecerán sus capturas y gráfica de precio.</p></div>; })()}
        </section>}
      </div>
    </main>
    <nav className="mobile-nav" aria-label="Navegación móvil">
      <button className={view === 'products' ? 'active' : ''} aria-current={view === 'products' ? 'page' : undefined} onClick={() => setView('products')}><ShoppingBag size={18}/><span>Productos</span><span className="mobile-nav-count">{products.length}</span></button>
      <button className={view === 'history' ? 'active' : ''} aria-current={view === 'history' ? 'page' : undefined} onClick={() => setView('history')}><History size={18}/><span>Historial</span></button>
    </nav>
    {reviewPreview && <ProductReviewModal url={reviewPreview.url} preview={reviewPreview} onClose={closeReview} onSave={saveReviewedProduct}/>}
  </div>;
}

export default App;
