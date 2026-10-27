// Menü, Untermenü Leistungen, Kopfzeilen-Schatten und Nach-oben-Knopf

const kopf = document.querySelector("header.kopf");
const knopf = document.querySelector(".menu-knopf");
const unter = document.querySelector(".hat-unter");
const unterKnopf = unter.querySelector("button");

knopf.addEventListener("click", () => {
  const offen = kopf.classList.toggle("offen");
  knopf.setAttribute("aria-expanded", offen);
  knopf.setAttribute("aria-label", offen ? "Menü schließen" : "Menü öffnen");
});
unterKnopf.addEventListener("click", e => {
  e.stopPropagation();
  unterKnopf.setAttribute("aria-expanded", unter.classList.toggle("offen"));
});
document.addEventListener("click", e => {
  if (!unter.contains(e.target)) { unter.classList.remove("offen"); unterKnopf.setAttribute("aria-expanded", false); }
});
document.addEventListener("keydown", e => {
  if (e.key !== "Escape") return;
  unter.classList.remove("offen"); unterKnopf.setAttribute("aria-expanded", false);
  kopf.classList.remove("offen"); knopf.setAttribute("aria-expanded", false);
});
document.querySelectorAll("#hauptnav a").forEach(a => a.addEventListener("click", () => {
  kopf.classList.remove("offen"); knopf.setAttribute("aria-expanded", false);
}));

const nachOben = document.querySelector(".nach-oben");
const beimScrollen = () => {
  kopf.classList.toggle("gescrollt", scrollY > 10);
  nachOben.classList.toggle("sichtbar", scrollY > 600);
};
addEventListener("scroll", beimScrollen, { passive: true });
beimScrollen();
