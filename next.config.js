/** @type {import('next').NextConfig} */
const nextConfig = {
  // Shared hosting runs Apache + PHP, not Node — so the site is exported as
  // plain HTML/CSS/JS into public_html and the backend is PHP. `next build`
  // writes the exported site to ./out.
  output: "export",

  // The Next image optimiser needs a Node server. Without one, images are
  // served as-is; they are already sized correctly in /public.
  images: { unoptimized: true },

  // Write /about/index.html rather than /about.html, which Apache serves
  // without a rewrite. public_html/.htaccess handles either form.
  trailingSlash: true,
};

module.exports = nextConfig;
