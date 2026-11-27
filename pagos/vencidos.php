<?php
// Clientes vencidos se unió al módulo Adeudos (filtro "Fuera de gracia");
// este enlace sigue funcionando.
header("Location: adeudos.php?situacion=vencido", true, 302);
exit;
