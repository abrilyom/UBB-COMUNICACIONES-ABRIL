<!DOCTYPE html>
<html lang="es">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Aviso de Suspensión - UBB Comunicaciones</title>

   <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
   <div id="contenedor-burbujas"></div>
   <main class="contenedor-aviso">

      <img src="assets/img/logo-ubb.jpg" alt="Logo UBB Comunicaciones" class="logo">

      <!-- 2. El título principal del aviso -->
      <h1>Aviso sobre su Servicio</h1>

      <!-- 3. El mensaje para el cliente (Enfocado en la comunicación) -->
      <p>Estimado cliente, su servicio de internet se encuentra temporalmente en pausa.</p>
      <p>Por favor, comuníquese con nuestro equipo de soporte para revisar el estado de su cuenta y ayudarle a reactivarlo a la brevedad.</p>

      <!-- 4. El botón de WhatsApp (Este lo dejamos intacto como pediste) -->
      <a href="#" class="boton-contacto">Reportar mi pago por WhatsApp</a>



   </main>
   <!-- Este es el código PHP que lee la IP de quien visita la página -->
   <div style="margin-top: 20px; padding: 10px; background-color: #f0f0f5; border-radius: 8px;">
      <p style="margin-bottom: 0; font-size: 14px; color: #555;">
         <!--  Información para soporte técnico:<br>
         <strong>Tu IP actual es: <?php echo $_SERVER['REMOTE_ADDR']; ?></strong>
-->
      </p>
   </div>

   <footer>
      <p>¿Algo no está correcto? Háznoslo saber.</p>
      <p>UBB-Comunicaciones | Tel: 2382114997, 2382085485</p>
      <p>Correo: ubbnetworks@gmail.com</p>
   </footer>

   <script src="assets/js/main.js"></script>
</body>

</html>