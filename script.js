document.documentElement.classList.add("has-js");

const $ = (selector) => document.querySelector(selector);

function isWebUrl(value) {
  try {
    const url = new URL(value);
    return ["https:", "http:"].includes(url.protocol) && !/YOUR_PAGE|YOUR_ACCOUNT/.test(value);
  } catch {
    return false;
  }
}

function setLink(id, url) {
  const element = document.getElementById(id);
  if (!element) return;
  if (isWebUrl(url)) {
    element.href = url;
    element.removeAttribute("aria-disabled");
    element.removeAttribute("tabindex");
  } else {
    element.removeAttribute("href");
    element.setAttribute("aria-disabled", "true");
    element.setAttribute("tabindex", "-1");
  }
}

// Graceful configuration handling: if config.js is missing or fails to load,
// fallback to existing static HTML links without throwing unhandled exceptions.
const config = typeof TECHTALK !== "undefined" ? TECHTALK : null;
if (config && config.links) {
  setLink("facebookLink", config.links.facebook);
  setLink("primaryContactLink", config.links.facebook);
  setLink("tiktokLink", config.links.tiktok);
  setLink("carousellLink", config.links.carousell);
  const hasDirectChat = isWebUrl(config.links.messenger);
  setLink("messengerLink", hasDirectChat ? config.links.messenger : config.links.facebook);
  const msgStrong = $("#messengerLink strong");
  const msgSmall = $("#messengerLink small");
  if (msgStrong) msgStrong.textContent = hasDirectChat ? "Chat with TechTalk" : "Contact via Facebook";
  if (msgSmall) msgSmall.textContent = hasDirectChat ? "Inquire, ask or send your offer" : "Open our page to inquire";
  setLink("googleReviewLink", config.links.googleReviews);
  if (isWebUrl(config.links.googleReviews)) {
    const revStrong = $("#googleReviewLink strong");
    const revSmall = $("#googleReviewLink small");
    if (revStrong) revStrong.textContent = "Google Reviews";
    if (revSmall) revSmall.textContent = "View our business on Google Maps";
  }
}

const yearEl = $("#year");
if (yearEl) yearEl.textContent = new Date().getFullYear();

function showToast(message) {
  const toast = $("#toast");
  if (!toast) return;
  toast.textContent = message;
  toast.classList.add("show");
  window.clearTimeout(showToast.timeout);
  showToast.timeout = window.setTimeout(() => toast.classList.remove("show"), 2800);
}

function escapeVCardText(value) {
  return String(value).replace(/\\/g, "\\\\").replace(/\r\n|\r|\n/g, "\\n").replace(/;/g, "\\;").replace(/,/g, "\\,");
}

// Fold by UTF-8 octets without splitting a Unicode code point (75 octets per line).
function foldVCardLine(value) {
  const encoder = new TextEncoder();
  const lines = [];
  let line = "";
  let length = 0;
  for (const character of value) {
    const size = encoder.encode(character).length;
    if (length + size > 75) {
      lines.push(line);
      line = " ";
      length = 1;
    }
    line += character;
    length += size;
  }
  lines.push(line);
  return lines.join("\r\n");
}

function createContactVCard() {
  const phone = TECHTALK.contact.phone || "";
  const email = TECHTALK.contact.email || "";
  const website = TECHTALK.contact.website;
  const address = TECHTALK.contact.address || "Meetups by appointment";

  if (!isWebUrl(website) || new URL(website).protocol !== "https:" || /[\r\n]/.test(`${phone}${email}${website}`)) {
    throw new Error("Invalid contact configuration");
  }

  return [
    "BEGIN:VCARD",
    "VERSION:3.0",
    `FN:${escapeVCardText(TECHTALK.businessName)}`,
    `N:${escapeVCardText(TECHTALK.businessName)};;;;`,
    `ORG:${escapeVCardText(TECHTALK.businessName)}`,
    `TITLE:${escapeVCardText(TECHTALK.tagline)}`,
    phone ? `TEL;TYPE=WORK,VOICE:${phone}` : "",
    email ? `EMAIL:${email}` : "",
    website ? `URL:${website}` : "",
    `NOTE:${escapeVCardText(address)}`,
    "END:VCARD"
  ].filter(Boolean).map(foldVCardLine).join("\r\n") + "\r\n";
}

$("#saveContactButton").addEventListener("click", () => {
  let url;
  let download;
  try {
    const blob = new Blob([createContactVCard()], { type: "text/vcard;charset=utf-8" });
    url = URL.createObjectURL(blob);
    download = document.createElement("a");
    download.href = url;
    download.download = "TechTalk.vcf";
    document.body.appendChild(download);
    download.click();
    showToast("Contact file download requested. Open TechTalk.vcf to save it.");
  } catch {
    showToast("Could not start the contact download. Please try again or contact us via Facebook.");
  } finally {
    download?.remove();
    // Give browsers time to consume the object URL before releasing it.
    if (url) window.setTimeout(() => URL.revokeObjectURL(url), 60000);
  }
});


// Products: native small carousel, larger grid and local page navigation; no autoplay.
(() => {
  const slider = document.getElementById("unitsSlider");
  if (!slider) return;
  const section = document.getElementById("available-units");
  const cards = Array.from(slider.querySelectorAll(".unit-card"));
  const prev = document.getElementById("unitsPrev"), next = document.getElementById("unitsNext");
  const rail = document.getElementById("unitsDots");
  const footer = section.querySelector(".units-slider-footer");
  const controls = prev?.parentElement;
  const motion = matchMedia("(prefers-reduced-motion: reduce)");
  // Expiration is a UI safeguard, not an automatic source check. Static dates remain visible.
  const qualifyStale = () => cards.forEach(card => {
    const stock = card.dataset.stockValidUntil, price = card.dataset.priceValidUntil;
    if (stock && Date.parse(stock) <= Date.now()) card.querySelector(".unit-status").textContent = "Availability needs confirmation";
    if (price && Date.parse(price) <= Date.now()) card.querySelector(".unit-price").textContent = "Price needs confirmation";
  });
  qualifyStale();
  document.addEventListener("visibilitychange", () => { if (!document.hidden) qualifyStale(); });
  if (section.classList.contains("catalog-grid-mode")) {
    if (controls) controls.hidden = true;
    if (footer) footer.hidden = true;
    const size = Number(section.dataset.pageSize) || 12;
    const pages = Math.ceil(cards.length / size), pager = section.querySelector(".catalog-pagination");
    let page = 0;
    const showPage = (index, manual = false) => {
      page = Math.max(0, Math.min(pages - 1, index));
      cards.forEach((card, i) => { card.hidden = Math.floor(i / size) !== page; });
      document.getElementById("catalogPageLabel").textContent = `Page ${page + 1} of ${pages}`;
      document.getElementById("catalogPrev").disabled = page === 0;
      document.getElementById("catalogNext").disabled = page === pages - 1;
      if (manual) {
        section.focus({preventScroll:true});
        section.scrollIntoView({block:"start", behavior:motion.matches ? "instant" : "smooth"});
        document.getElementById("catalogStatus").textContent = `Products ${page * size + 1}–${Math.min(cards.length, (page + 1) * size)} of ${cards.length}. Page ${page + 1} of ${pages}.`;
      }
    };
    if (pages > 1 && pager) {
      pager.hidden = false;
      document.getElementById("catalogPrev").addEventListener("click", () => showPage(page - 1, true));
      document.getElementById("catalogNext").addEventListener("click", () => showPage(page + 1, true));
      showPage(0);
    }
    return;
  }
  let positions = [0], dots = [];
  const nearest = left => positions.reduce((best, position, i) => Math.abs(position-left)<Math.abs(positions[best]-left)?i:best,0);
  const update = () => {
    const current = nearest(slider.scrollLeft);
    dots.forEach((dot,i) => { dot.classList.toggle("active",i===current); dot.setAttribute("aria-current",String(i===current)); });
    if (prev) prev.disabled = slider.scrollLeft <= 2;
    if (next) next.disabled = slider.scrollLeft >= positions[positions.length-1]-2;
  };
  const go = index => slider.scrollTo({left:positions[Math.max(0,Math.min(positions.length-1,index))],behavior:motion.matches?"instant":"smooth"});
  const rebuild = () => {
    const max = Math.max(0,slider.scrollWidth-slider.clientWidth), start = cards[0]?.getBoundingClientRect().left || 0;
    positions = [0];
    cards.forEach(card => { const left = Math.max(0,Math.min(max,card.getBoundingClientRect().left-start)); if(left-positions[positions.length-1]>2)positions.push(left); });
    const scrollable = positions.length>1;
    if(controls)controls.hidden=!scrollable;
    if(footer)footer.hidden=!scrollable;
    // Derive indicators from reachable scroll positions, never from fixed HTML dots.
    const focused = rail?.contains(document.activeElement);
    const selected = nearest(slider.scrollLeft);
    if(rail){
      rail.replaceChildren(...positions.map((_,i) => { const dot=document.createElement("button");dot.type="button";dot.className="units-dot";dot.dataset.slide=i;dot.setAttribute("aria-label",`Show product position ${i+1} of ${positions.length}`);dot.setAttribute("aria-controls","unitsSlider");return dot; }));
      dots=Array.from(rail.children);
      if(focused)dots[selected]?.focus({preventScroll:true});
    }
    slider.scrollTo({left:positions[selected],behavior:"instant"});update();
  };
  prev?.addEventListener("click",()=>go(nearest(slider.scrollLeft)-1));
  next?.addEventListener("click",()=>go(nearest(slider.scrollLeft)+1));
  rail?.addEventListener("click",event=>{const dot=event.target.closest(".units-dot");if(dot&&rail.contains(dot))go(Number(dot.dataset.slide));});
  slider.addEventListener("keydown",event=>{if(event.target!==slider)return;const i=nearest(slider.scrollLeft),targets={ArrowLeft:i-1,ArrowRight:i+1,Home:0,End:positions.length-1};if(Object.hasOwn(targets,event.key)){event.preventDefault();go(targets[event.key]);}});
  slider.addEventListener("scroll",update,{passive:true});
  let frame;
  const schedule=()=>{cancelAnimationFrame(frame);frame=requestAnimationFrame(rebuild);};
  window.addEventListener("resize",schedule);
  if("ResizeObserver" in window)new ResizeObserver(schedule).observe(slider);
  rebuild();
})();


// Review playback separates persistent user choice from temporary interaction pauses.
(() => {
  const carousel = document.getElementById("carousell-reviews");
  const track = document.getElementById("reviewsTrack");
  const windowEl = document.getElementById("reviewsWindow");
  const toggle = document.getElementById("reviewToggle");
  const prevBtn = document.getElementById("reviewPrev");
  const nextBtn = document.getElementById("reviewNext");
  const progressBar = document.getElementById("reviewsProgressFill");
  const note = document.getElementById("reviewPlaybackNote");
  const status = document.getElementById("reviewStatus");
  const dotRail = document.getElementById("reviewsDots");
  let dots = Array.from(document.querySelectorAll(".review-dot"));
  let slides = track ? Array.from(track.querySelectorAll(".review-slide")) : [];
  if (!carousel || !track || !toggle) return;
  const indexReadout = document.getElementById("reviewsIndex");

  const motion = window.matchMedia("(prefers-reduced-motion: reduce)");
  let active = 0;
  let timer = null;
  let userPaused = motion.matches;
  let hovered = false;
  let touching = false;
  const hasFocus = () => carousel.contains(document.activeElement);

  function showReview(index, announce = false) {
    active = slides.length ? (index + slides.length) % slides.length : 0;
    track.style.transform = `translateX(-${active * 100}%)`;
    slides.forEach((slide, i) => {
      const selected = i === active;
      slide.setAttribute("aria-hidden", selected ? "false" : "true");
      slide.inert = !selected;
      slide.querySelectorAll(".review-live-link").forEach(link => { link.tabIndex = selected ? 0 : -1; });
    });
    dots.forEach((dot, i) => {
      dot.classList.toggle("active", i === active);
      dot.setAttribute("aria-current", i === active ? "true" : "false");
      dot.setAttribute("aria-controls", slides[i]?.id || "reviewsTrack");
    });
    if (indexReadout) indexReadout.textContent = !slides.length ? "00 / 00" : `${String(active + 1).padStart(2, "0")} / ${String(slides.length).padStart(2, "0")}`;
    if (progressBar) {
      progressBar.style.width = `${slides.length ? ((active + 1) / slides.length) * 100 : 0}%`;
      const meter = progressBar.parentElement;
      meter.hidden = !slides.length;
      meter.setAttribute("aria-valuemin", slides.length ? "1" : "0");
      meter.setAttribute("aria-valuenow", String(slides.length ? active + 1 : 0));
      meter.setAttribute("aria-valuemax", String(slides.length));
      meter.setAttribute("aria-valuetext", slides.length ? `Review ${active + 1} of ${slides.length}` : "No featured testimonials");
    }
    if (prevBtn) prevBtn.disabled = slides.length < 2;
    if (nextBtn) nextBtn.disabled = slides.length < 2;
    // Reveal the active indicator within its rail without moving the page.
    if (dotRail && dots[active] && dotRail.clientWidth > 0 && dotRail.scrollWidth > dotRail.clientWidth) {
      const railBounds = dotRail.getBoundingClientRect();
      const dotBounds = dots[active].getBoundingClientRect();
      const leftEdge = railBounds.left + dotRail.clientLeft;
      const rightEdge = leftEdge + dotRail.clientWidth;
      const offset = dotBounds.left < leftEdge ? dotBounds.left - leftEdge
        : dotBounds.right > rightEdge ? dotBounds.right - rightEdge : 0;
      if (offset) dotRail.scrollTo({
        left: dotRail.scrollLeft + offset,
        behavior: motion.matches ? "instant" : "smooth"
      });
    }
    // Automatic changes stay quiet; manual selection announces position and attribution.
    if (announce && status) status.textContent = slides.length ? slides[active].getAttribute("aria-label") : "No featured testimonials";
  }

  function syncPlayback() {
    clearInterval(timer);
    timer = null;
    const canPlay = !userPaused && !motion.matches && !hasFocus() && !hovered && !touching && !document.hidden && slides.length > 1;
    if (canPlay) timer = setInterval(() => showReview(active + 1), 4000);
    toggle.disabled = motion.matches || slides.length < 2;
    toggle.textContent = motion.matches ? "Auto-play off" : userPaused ? "Resume reviews" : "Pause reviews";
    if (note) {
      note.textContent = slides.length < 2 ? "Select a source link to explore customer feedback." : motion.matches ? "Automatic playback is off for reduced motion. Select a review below."
        : userPaused ? "Automatic playback paused. Select any review below."
        : hasFocus() ? "Paused while you use the review controls."
        : hovered || touching ? "Paused while you read."
        : document.hidden ? "Paused while this tab is hidden."
        : "Reviews change every 4 seconds.";
    }
  }

  toggle.addEventListener("click", () => {
    userPaused = !userPaused;
    syncPlayback();
  });
  prevBtn?.addEventListener("click", () => {
    showReview(active - 1, true);
    syncPlayback();
  });
  nextBtn?.addEventListener("click", () => {
    showReview(active + 1, true);
    syncPlayback();
  });
  // Delegate indicators so a regenerated collection needs no duplicate listeners.
  dotRail?.addEventListener("click", event => {
    const dot = event.target.closest(".review-dot");
    if (!dot || !dotRail.contains(dot)) return;
    showReview(Number(dot.dataset.reviewSlide), true);
    syncPlayback();
  });
  let pendingReviews = null;
  function applyReviews(reviews) {
    const model = window.TechTalkReviewModel;
    const selectedId = slides[active]?.dataset.reviewId;
    track.innerHTML = model.slides(reviews);
    dotRail.innerHTML = model.dots(reviews);
    slides = Array.from(track.querySelectorAll(".review-slide"));
    dots = Array.from(dotRail.querySelectorAll(".review-dot"));
    const badge = document.getElementById("reviewHeroBadge");
    if (badge) badge.textContent = model.badge(reviews.length);
    const count = document.getElementById("reviewCount");
    if (count) count.innerHTML = reviews.length ? `<strong>${reviews.length}</strong> Customer Testimonial${reviews.length === 1 ? "" : "s"} Featured` : "Customer feedback";
    const carousellOnly = reviews.length && reviews.every(r => r.sourcePlatform === "carousell");
    carousel.querySelector(".reviews-intro").textContent = carousellOnly ? "Customer experiences shared through our Carousell profile." : "Selected customer feedback, with source information for each testimonial.";
    carousel.querySelector(".review-source-label").textContent = carousellOnly ? "Customer Reviews · Carousell @levjatics" : "Customer feedback · sources identified on each entry";
    const next = slides.findIndex(slide => slide.dataset.reviewId === selectedId);
    showReview(Math.max(0, next));
    syncPlayback();
  }
  // Explicit local refresh only: no polling, new timers, or automatic platform requests.
  window.TechTalkReviews = {
    async refresh() {
      try {
        const response = await fetch("reviews-data.json", {cache:"no-store"});
        if (!response.ok) throw new Error("Review dataset unavailable");
        const reviews = window.TechTalkReviewModel.validatePublic(await response.json());
        // Preserve focused links and controls until focus leaves the carousel.
        if (hasFocus()) pendingReviews = reviews;
        else applyReviews(reviews);
        return {ok:true, count:reviews.length, deferred:hasFocus()};
      } catch (_) {
        // Keep generated static content; never replace valid feedback with broken data.
        return {ok:false, reason:"Review data unavailable or invalid; existing content preserved"};
      }
    }
  };
  carousel.addEventListener("focusin", syncPlayback);
  carousel.addEventListener("focusout", () => queueMicrotask(() => {
    if (!hasFocus() && pendingReviews) { const next = pendingReviews; pendingReviews = null; applyReviews(next); }
    syncPlayback();
  }));
  windowEl?.addEventListener("mouseenter", () => { hovered = true; syncPlayback(); });
  windowEl?.addEventListener("mouseleave", () => { hovered = false; syncPlayback(); });
  windowEl?.addEventListener("touchstart", () => { touching = true; syncPlayback(); }, { passive: true });
  const endTouch = () => { touching = false; syncPlayback(); };
  windowEl?.addEventListener("touchend", endTouch, { passive: true });
  windowEl?.addEventListener("touchcancel", endTouch, { passive: true });
  document.addEventListener("visibilitychange", syncPlayback);
  motion.addEventListener("change", () => {
    // Returning from reduced motion must not restart playback without consent.
    if (motion.matches) userPaused = true;
    syncPlayback();
  });

  showReview(0);
  syncPlayback();
})();

// Product Inquiry Context: Copy Details action for prospective buyers.
(() => {
  document.querySelectorAll(".unit-copy-btn").forEach(btn => {
    btn.addEventListener("click", async () => {
      const text = btn.getAttribute("data-copy-text") || "";
      const ref = btn.getAttribute("data-ref") || "Product";
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
          showToast(`Copied ${ref} details! Paste when messaging on Facebook.`);
        } else {
          showToast(`Inquiry Ref: ${ref} — message us on Facebook!`);
        }
      } catch {
        showToast(`Inquiry Ref: ${ref} — message us on Facebook!`);
      }
    });
  });
})();


/* PHASE E — editorial navigation chrome.
   One passive scroll listener and one rAF writes the progress custom property used by CSS,
   toggles the fixed bar, and marks the section currently under the bar. */
(() => {
  const bar = document.getElementById("signalBar");
  if (!bar) return;
  const root = document.documentElement;
  const readout = document.getElementById("signalProgressValue");
  const links = Array.from(bar.querySelectorAll(".signal-bar-nav a"));
  const sections = links
    .map(link => ({ link, section: document.querySelector(link.getAttribute("href")) }))
    .filter(item => item.section);
  let frame = 0;

  const render = () => {
    frame = 0;
    const max = Math.max(1, root.scrollHeight - window.innerHeight);
    const progress = Math.max(0, Math.min(1, window.scrollY / max));
    root.style.setProperty("--signal-p", progress.toFixed(4));
    if (readout) readout.textContent = `${Math.round(progress * 100)}%`;
    bar.classList.toggle("on", window.scrollY > 120);

    let current = null;
    for (const item of sections) {
      if (item.section.getBoundingClientRect().top <= 96) current = item;
    }
    for (const item of sections) {
      if (item === current) item.link.setAttribute("aria-current", "location");
      else item.link.removeAttribute("aria-current");
    }
  };
  const schedule = () => { if (!frame) frame = requestAnimationFrame(render); };
  window.addEventListener("scroll", schedule, { passive: true });
  window.addEventListener("resize", schedule);
  render();
})();

/* PHASE E — hero pointer light.
   Fine pointers only; skipped for reduced motion; coordinates are relative to the hero. */
(() => {
  const hero = document.querySelector(".hero");
  if (!hero) return;
  const precise = window.matchMedia("(hover: hover) and (pointer: fine) and (min-width: 768px)");
  const reduced = window.matchMedia("(prefers-reduced-motion: reduce)");
  let frame = 0;
  let point = null;

  const render = () => {
    frame = 0;
    if (!point) return;
    hero.style.setProperty("--hero-x", `${point.x.toFixed(0)}px`);
    hero.style.setProperty("--hero-y", `${point.y.toFixed(0)}px`);
  };
  const reset = () => {
    cancelAnimationFrame(frame);
    frame = 0;
    point = null;
    hero.removeAttribute("data-pointer");
    hero.style.removeProperty("--hero-x");
    hero.style.removeProperty("--hero-y");
  };

  hero.addEventListener("pointermove", event => {
    if (!precise.matches || reduced.matches || event.pointerType !== "mouse") return;
    const bounds = hero.getBoundingClientRect();
    point = { x: event.clientX - bounds.left, y: event.clientY - bounds.top };
    if (!hero.hasAttribute("data-pointer")) hero.setAttribute("data-pointer", "on");
    if (!frame) frame = requestAnimationFrame(render);
  }, { passive: true });
  hero.addEventListener("pointerleave", reset, { passive: true });
  precise.addEventListener("change", reset);
  reduced.addEventListener("change", reset);
})();
