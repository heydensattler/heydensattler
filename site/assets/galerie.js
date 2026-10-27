// Galerie der Angebotsseiten: Blättern per Knopf, Pfeiltaste, Wischen und Vorschaubild.
// Ohne JavaScript bleibt eine waagerecht scrollbare Bildleiste mit Ankern.

document.querySelectorAll(".galerie").forEach(galerie => {
  const liste = galerie.querySelector(".galerie-liste");
  const folien = [...liste.children];
  const zaehler = galerie.querySelector(".galerie-zaehler");
  const vorschau = [...galerie.querySelectorAll(".galerie-vorschau a")];
  const leiste = galerie.querySelector(".galerie-vorschau");
  const zurueck = galerie.querySelector(".galerie-zurueck");
  const weiter = galerie.querySelector(".galerie-weiter");
  const ruhig = matchMedia("(prefers-reduced-motion: reduce)");
  let aktuell = 0;

  const markieren = () => {
    zaehler.textContent = `${aktuell + 1} / ${folien.length}`;
    vorschau.forEach((a, n) => n === aktuell ? a.setAttribute("aria-current", "true") : a.removeAttribute("aria-current"));
    const a = vorschau[aktuell];
    if (a) leiste.scrollTo({ left: a.offsetLeft - (leiste.clientWidth - a.clientWidth) / 2, behavior: ruhig.matches ? "auto" : "smooth" });
  };
  const zeigen = n => {
    aktuell = (n + folien.length) % folien.length;
    liste.scrollTo({ left: aktuell * liste.clientWidth, behavior: ruhig.matches ? "auto" : "smooth" });
    markieren();
  };

  if (folien.length > 1) {
    zurueck.hidden = weiter.hidden = false;
    zurueck.addEventListener("click", () => zeigen(aktuell - 1));
    weiter.addEventListener("click", () => zeigen(aktuell + 1));
  }
  vorschau.forEach((a, n) => a.addEventListener("click", e => { e.preventDefault(); zeigen(n); }));
  liste.addEventListener("keydown", e => {
    const ziel = { ArrowLeft: aktuell - 1, ArrowRight: aktuell + 1, Home: 0, End: folien.length - 1 }[e.key];
    if (ziel === undefined) return;
    e.preventDefault();
    zeigen(ziel);
  });

  // Wischen oder Scrollen von Hand: Zähler und Vorschau nachziehen
  let warten;
  liste.addEventListener("scroll", () => {
    clearTimeout(warten);
    warten = setTimeout(() => {
      const n = Math.round(liste.scrollLeft / liste.clientWidth);
      if (n !== aktuell) { aktuell = n; markieren(); }
    }, 80);
  }, { passive: true });
  addEventListener("resize", () => { liste.scrollLeft = aktuell * liste.clientWidth; });
});

// Vollbild: Klick aufs Bild (oder Knopf „Vergrößern“) öffnet alle Bilder groß, unten eine Vorschauleiste.
// Blättern per Pfeil, Pfeiltaste, Wischen und Vorschaubild; Esc oder Schließen beendet und lässt die Galerie
// auf dem zuletzt gesehenen Bild stehen.

const PFEIL_LINKS = '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M11 3 5 9l6 6"/></svg>';
const PFEIL_RECHTS = '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m7 3 6 6-6 6"/></svg>';
const KREUZ = '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 4l10 10M14 4 4 14"/></svg>';
const LUPE = '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 7V3h4M15 7V3h-4M3 11v4h4M15 11v4h-4"/></svg>';

document.querySelectorAll(".galerie").forEach(galerie => {
  if (typeof HTMLDialogElement !== "function") return;
  const liste = galerie.querySelector(".galerie-liste");
  const folien = [...liste.children];
  const daten = folien.map((li, n) => {
    const img = li.querySelector("img");
    const vorschau = galerie.querySelectorAll(".galerie-vorschau img")[n];
    return {
      src: img.currentSrc || img.src, srcset: img.getAttribute("srcset") || "", alt: img.alt,
      titel: li.querySelector("figcaption")?.textContent || "", plan: li.classList.contains("plan"),
      klein: vorschau ? vorschau.src : img.src,
    };
  });
  if (!daten.length) return;

  const dialog = document.createElement("dialog");
  dialog.className = "vollbild";
  dialog.setAttribute("aria-label", "Bilder im Vollbild");
  dialog.innerHTML = `
    <div class="vollbild-kopf">
      <p><span class="vollbild-zaehler" aria-live="polite"></span> <span class="vollbild-titel"></span></p>
      <button class="vollbild-knopf vollbild-schliessen" type="button" aria-label="Vollbild schließen">${KREUZ}</button>
    </div>
    <div class="vollbild-buehne">
      <img alt="" decoding="async">
      <button class="vollbild-knopf vollbild-zurueck" type="button" aria-label="Vorheriges Bild">${PFEIL_LINKS}</button>
      <button class="vollbild-knopf vollbild-weiter" type="button" aria-label="Nächstes Bild">${PFEIL_RECHTS}</button>
    </div>
    <ul class="vollbild-leiste" aria-label="Alle Bilder">${daten.map((d, n) => `
      <li><button type="button" aria-label="Bild ${n + 1}: ${d.titel.replace(/"/g, "&quot;")}"><img src="${d.klein}" alt="" width="64" height="64" loading="lazy" decoding="async"></button></li>`).join("")}
    </ul>`;
  document.body.append(dialog);

  const buehne = dialog.querySelector(".vollbild-buehne");
  const bild = buehne.querySelector("img");
  const zaehler = dialog.querySelector(".vollbild-zaehler");
  const titel = dialog.querySelector(".vollbild-titel");
  const leiste = dialog.querySelector(".vollbild-leiste");
  const knoepfe = [...leiste.querySelectorAll("button")];
  const ruhig = matchMedia("(prefers-reduced-motion: reduce)");
  let aktuell = 0, wechsel = 0;

  const vorladen = n => { const d = daten[(n + daten.length) % daten.length]; const i = new Image(); i.srcset = d.srcset; i.sizes = "100vw"; i.src = d.src; };
  const zeigen = n => {
    aktuell = (n + daten.length) % daten.length;
    const d = daten[aktuell], diesesMal = ++wechsel;
    // Erst laden, dann tauschen: so bleibt die Bühne beim Springen nicht leer
    const neu = new Image();
    if (d.srcset) { neu.srcset = d.srcset; neu.sizes = "100vw"; }
    neu.src = d.src;
    const tauschen = () => {
      if (diesesMal !== wechsel) return;
      bild.removeAttribute("srcset");
      bild.src = d.src;
      if (d.srcset) { bild.srcset = d.srcset; bild.sizes = "100vw"; }
      bild.alt = d.alt;
      buehne.classList.toggle("plan", d.plan);
    };
    if (neu.complete) tauschen(); else neu.onload = neu.onerror = tauschen;
    zaehler.textContent = `${aktuell + 1} / ${daten.length}`;
    titel.textContent = d.titel;
    knoepfe.forEach((k, i) => i === aktuell ? k.setAttribute("aria-current", "true") : k.removeAttribute("aria-current"));
    const k = knoepfe[aktuell].parentElement;
    leiste.scrollTo({ left: k.offsetLeft - (leiste.clientWidth - k.clientWidth) / 2, behavior: ruhig.matches ? "auto" : "smooth" });
    if (daten.length > 1) { vorladen(aktuell + 1); vorladen(aktuell - 1); }
  };
  const oeffnen = n => {
    document.documentElement.classList.add("vollbild-offen");
    dialog.showModal();
    zeigen(n);
  };

  // Aufräumen gleich beim Schließen, nicht erst im close-Ereignis (das kommt in manchen Browsern spät)
  const nachSchliessen = () => {
    if (!document.documentElement.classList.contains("vollbild-offen")) return;
    document.documentElement.classList.remove("vollbild-offen");
    // Galerie auf das zuletzt gesehene Bild stellen (ohne weiches Scrollen, das Vollbild lag ja darüber)
    liste.scrollLeft = aktuell * liste.clientWidth;
    galerie.querySelectorAll(".galerie-vorschau a")[aktuell]?.click();
  };
  const schliessen = () => { dialog.close(); nachSchliessen(); };
  dialog.querySelector(".vollbild-schliessen").addEventListener("click", schliessen);
  dialog.addEventListener("cancel", e => { e.preventDefault(); schliessen(); });
  dialog.addEventListener("close", nachSchliessen);
  const zurueck = dialog.querySelector(".vollbild-zurueck"), weiter = dialog.querySelector(".vollbild-weiter");
  zurueck.hidden = weiter.hidden = daten.length < 2;
  zurueck.addEventListener("click", () => zeigen(aktuell - 1));
  weiter.addEventListener("click", () => zeigen(aktuell + 1));
  knoepfe.forEach((k, n) => k.addEventListener("click", () => zeigen(n)));
  dialog.addEventListener("keydown", e => {
    const ziel = { ArrowLeft: aktuell - 1, ArrowRight: aktuell + 1, Home: 0, End: daten.length - 1 }[e.key];
    if (ziel === undefined) return;
    e.preventDefault();
    zeigen(ziel);
  });

  // Wischen auf dem Bild
  let start = null;
  buehne.addEventListener("pointerdown", e => { start = [e.clientX, e.clientY]; });
  buehne.addEventListener("pointerup", e => {
    if (!start) return;
    const dx = e.clientX - start[0], dy = e.clientY - start[1];
    start = null;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) zeigen(aktuell + (dx < 0 ? 1 : -1));
  });

  // Öffnen: Klick aufs Bild, Eingabetaste in der Galerie oder Knopf „Vergrößern“
  folien.forEach((li, n) => li.querySelector("img").addEventListener("click", () => oeffnen(n)));
  const sichtbar = () => Math.round(liste.scrollLeft / liste.clientWidth);
  liste.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); oeffnen(sichtbar()); } });
  const gross = document.createElement("button");
  gross.type = "button";
  gross.className = "galerie-knopf galerie-gross";
  gross.setAttribute("aria-label", "Bilder im Vollbild ansehen");
  gross.innerHTML = LUPE;
  gross.addEventListener("click", () => oeffnen(sichtbar()));
  galerie.querySelector(".galerie-buehne").append(gross);
});
