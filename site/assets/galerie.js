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
