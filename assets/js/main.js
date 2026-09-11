// Le decimos al navegador que espere a que todo el HTML (el esqueleto)
// termine de cargar antes de ejecutar este código.
document.addEventListener("DOMContentLoaded", function () {
  // 1. SELECCIONAR EL BOTÓN
  // Usamos querySelector para buscar el botón por su clase (igual que en CSS)
  const botonWhatsapp = document.querySelector(".boton-contacto");

  // 2. CONFIGURAR LOS DATOS
  // Usamos el código de país de México (52) seguido del número de la empresa
  const numeroTelefono = "522382114997";
  // Escribimos el mensaje que le aparecerá al cliente por defecto
  const mensaje =
    "Hola, mi servicio de internet está suspendido. Quisiera reportar mi pago o solicitar información para la reactivación.";

  // 3. DARLE ACCIÓN AL CLIC
  // Le agregamos un "escuchador de eventos" (Event Listener) que esté atento a cuando alguien haga clic
  botonWhatsapp.addEventListener("click", function (event) {
    // Evitamos que el enlace haga su acción por defecto (que era solo poner un '#' en la barra de direcciones)
    event.preventDefault();

    // Armamos la URL oficial de WhatsApp.
    // Usamos encodeURIComponent() para que los espacios del texto se conviertan en formato web (como %20)
    const urlWhatsApp = `https://wa.me/${numeroTelefono}?text=${encodeURIComponent(mensaje)}`;

    // Abrimos la URL en una pestaña nueva
    window.open(urlWhatsApp, "_blank");
  });
});

// --- NUEVO CÓDIGO: GENERADOR DE BURBUJAS ---
const contenedorBurbujas = document.getElementById("contenedor-burbujas");

// Vamos a crear 15 burbujas
for (let i = 0; i < 15; i++) {
  let burbuja = document.createElement("div");
  burbuja.classList.add("burbuja");

  // Valores aleatorios para que se vea natural
  let size = Math.random() * 40 + 10; // Tamaño entre 10px y 50px
  burbuja.style.width = `${size}px`;
  burbuja.style.height = `${size}px`;

  burbuja.style.left = `${Math.random() * 100}%`; // Posición horizontal aleatoria
  burbuja.style.animationDuration = `${Math.random() * 4 + 4}s`; // Duración entre 4s y 8s
  burbuja.style.animationDelay = `${Math.random() * 5}s`; // Retraso aleatorio al inicio

  contenedorBurbujas.appendChild(burbuja);
}
