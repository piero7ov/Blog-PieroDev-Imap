# 📬 IMAP Email Blog (Correos como Blog) — PieroDev

Este proyecto convierte tu **bandeja de entrada (Gmail vía IMAP)** en un **“blog”**: cada correo recibido se muestra como si fuera un artículo.

- ✅ **Asunto** → Título del post  
- ✅ **Remitente + fecha** → Meta del artículo  
- ✅ **Cuerpo del correo (HTML o texto)** → Contenido  
- ✅ **Primera imagen encontrada** → Imagen destacada (portada)  
- ✅ **Adjuntos e imágenes** → Listado para descarga  
- ✅ **Buscador por asunto** → Filtra posts  
- ✅ **Paginación** → Portada tipo grid (página a página)  
- ✅ **Mini panel de estadísticas** → Dashboard con cache (rápido)

---

## 🧠 ¿Qué problema resuelve?

Normalmente ver correos es “lista + abrir y leer”.  
Aquí se reinterpretan como “contenido” y se presentan con formato de blog:

- Portada con extracto (tipo resumen)
- Página de post completo
- Descargas de adjuntos como “recursos”
- Estadísticas para entender el uso del inbox

Ideal para proyectos de aprendizaje (IMAP, parsing MIME, PHP server-side) y para mostrar como demo.

---

## 🧩 Estructura del proyecto

```

/blog_pierodev_imap
│
├─ index.php              # Blog (portada + post completo + descargas)
├─ panel.php              # Panel de estadísticas (dashboard)
├─ config.php              # Configuración IMAP + límites + token panel
├─ lib_imap_blog.php       # Librería: extracción cuerpo, imágenes, adjuntos, helpers, stats
│
├─ /assets
│   └─ app.css             # Estilos (blog + panel)
│
├─ /logos                  # Iconos sociales
│   ├─ email.png
│   ├─ github.png
│   ├─ home.png
│   ├─ linkedin.png
│   └─ youtube.png
│
└─ /cache
└─ stats.json          # Cache del panel (se genera solo)

```

---

## ✅ Requisitos

- **PHP** (recomendado 8.x)
- Extensión **IMAP habilitada**
- Servidor local tipo **XAMPP** / Apache
- Cuenta de Gmail con **App Password** (si tienes 2FA)

> Nota: Gmail suele requerir “Contraseñas de aplicación” (App Password).  
> No uses tu contraseña normal.

---

## ⚙️ Instalación (XAMPP)

1) Copia la carpeta del proyecto en:

```

C:\xampp\htdocs\blog_pierodev_imap

```

2) Activa IMAP en PHP (si no está activo):

- Abre:  
  `C:\xampp\php\php.ini`
- Busca algo como:
  `;extension=imap`
- Quita el `;` para habilitarlo:
  `extension=imap`

Reinicia Apache desde el panel de XAMPP.

3) Configura tus credenciales en `config.php`

Ejemplo típico:
```php
define('IMAP_HOSTNAME', '{imap.gmail.com:993/imap/ssl}INBOX');
define('IMAP_USERNAME', 'tu_correo@gmail.com');
define('IMAP_PASSWORD', 'tu_app_password');
````

4. Abre el proyecto:

* Blog:
  `http://localhost/blog_pierodev_imap/`
* Panel:
  `http://localhost/blog_pierodev_imap/panel.php`

---

## 🔐 Seguridad del panel (opcional)

El panel puede protegerse con token.

En `config.php` define:

```php
define('PANEL_TOKEN', 'un_token_largo_y_dificil');
```

Y entra así:

```
panel.php?token=un_token_largo_y_dificil
```

Si `PANEL_TOKEN` está vacío, el panel queda abierto.

---

## 📊 Panel de estadísticas

`panel.php` muestra:

* Total de correos (ALL)
* No leídos (UNSEEN)
* Correos escaneados para stats (limitados para rendimiento)
* Correos por mes (últimos 12)
* Top remitentes
* Conteo de imágenes y adjuntos

### Cache (para que vaya rápido)

El panel guarda un cache en:

```
/cache/stats.json
```

La duración la controlas con:

```php
define('STATS_CACHE_TTL', 60); // segundos
```

Para forzar recálculo:

```
panel.php?refresh=1
```

---

## 🖼️ Descargas (cómo funcionan)

Dentro de un post completo:

* Descargar imágenes:

  ```
  ?dl_msg=NUM&dl_img=IDX
  ```
* Descargar adjuntos:

  ```
  ?dl_msg=NUM&dl=IDX
  ```

Esto se procesa **antes de imprimir HTML** para enviar headers correctos.

---

## 🚀 Ideas de mejoras (fáciles de agregar)

* ⭐ “Favoritos” (guardar msgno en JSON local)
* 🔎 Filtro por remitente y/o rango de fechas
* 🧵 Categorías por prefijo del asunto (ej: `[DEV]`, `[FACTURA]`)
* 🧼 Sanitizado más estricto del HTML (whitelist)
* 🖼️ Optimizar portada: limitar imágenes muy grandes (dataUri)

---

## ⚠️ Nota importante

Este proyecto lee tu correo vía IMAP.
Si lo vas a subir público:

✅ **NUNCA subas tu `config.php` con credenciales reales.**
Usa variables de entorno o un `config.sample.php`.

---

## 📄 Licencia

Proyecto educativo / demo.
Puedes adaptarlo libremente para tu portafolio.

---

## 👤 Autor

**Piero Olivares Velasquez**

* GitHub: `piero7ov`
* Portafolio: `piero7ov.github.io/Portafolio/`

```