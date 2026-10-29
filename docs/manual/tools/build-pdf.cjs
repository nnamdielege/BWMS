/**
 * Builds docs/manual/Local-Selection-WMS-User-Manual.pdf from USER-MANUAL.md.
 *
 *   npm run manual:pdf
 *
 * Markdown -> HTML with marked, images embedded from docs/manual/img (data URIs),
 * printed to A4 with Playwright's Chromium (first time: npx playwright install chromium).
 * Cover page, clickable contents, each top-level section on a new page,
 * logo header and "Page X of Y" footer.
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { Marked } = require('marked');
const { PDFDocument, rgb } = require('pdf-lib');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '../../..');
const MANUAL = path.resolve(__dirname, '..');
const SOURCE = path.join(MANUAL, 'USER-MANUAL.md');
const OUT = path.join(MANUAL, 'Local-Selection-WMS-User-Manual.pdf');
const LOGO = path.join(ROOT, 'public/images/logo.png');
const TITLE = 'Local Selection WMS: User Manual';
// Screenshots narrower than this are phone shots (390 px at 2x); they print centred at ~60% width.
const PHONE_MAX_PX = 900;

const dataUri = (file) => `data:image/png;base64,${fs.readFileSync(file).toString('base64')}`;
const pngWidth = (file) => fs.readFileSync(file).readUInt32BE(16); // IHDR width
const escape = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
// Same ids GitHub gives headings, so the manual's own #links keep working.
const slug = (text) =>
    text
        .toLowerCase()
        .replace(/[^\w\- ]+/g, '')
        .trim()
        .replace(/ /g, '-');

function version() {
    try {
        return execSync('git rev-parse --short HEAD', { cwd: ROOT }).toString().trim();
    } catch {
        return 'unversioned';
    }
}

function render(markdown) {
    // The PDF has its own contents page and cover, so drop the Markdown ones.
    const source = markdown.replace(/^# .*\n/, '').replace(/\*\*Contents\*\*\n\n(?:- .*\n)+\n/, '');

    const toc = [];
    const marked = new Marked({ gfm: true });
    marked.use({
        renderer: {
            heading({ tokens, depth, text }) {
                const id = slug(text);
                if (depth <= 3) toc.push({ depth, id, html: this.parser.parseInline(tokens) });
                return `<h${depth} id="${id}">${this.parser.parseInline(tokens)}</h${depth}>\n`;
            },
            image({ href, text }) {
                const file = path.join(MANUAL, href);
                if (!fs.existsSync(file)) throw new Error(`Missing image ${href}`);
                const cls = pngWidth(file) < PHONE_MAX_PX ? 'shot phone' : 'shot';
                return `<img class="${cls}" src="${dataUri(file)}" alt="${escape(text)}">`;
            },
        },
    });

    const body = marked.parse(source);
    const contents = toc
        .filter((h) => h.depth === 2 || h.depth === 3)
        .map((h) => `<li class="toc-${h.depth}"><a href="#${h.id}">${h.html}</a></li>`)
        .join('\n');

    return { body, contents };
}

const css = `
@page { size: A4; margin: 18mm 18mm 18mm 18mm; }
@page :first { margin: 0; }
* { box-sizing: border-box; }
html { font-family: "Segoe UI", system-ui, -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 11pt; color: #15201B; }
body { margin: 0; line-height: 1.45; }
a { color: #15201B; }
h1, h2, h3, h4 { line-height: 1.2; break-after: avoid; page-break-after: avoid; }
h2 { font-size: 20pt; margin: 0 0 10pt; padding-bottom: 4pt; border-bottom: 2px solid #15201B; break-before: page; page-break-before: always; }
h3 { font-size: 13.5pt; margin: 16pt 0 6pt; }
h4 { font-size: 11.5pt; margin: 12pt 0 4pt; }
p { margin: 0 0 7pt; }
ul, ol { margin: 0 0 8pt; padding-left: 18pt; }
li { margin: 2pt 0; break-inside: avoid; page-break-inside: avoid; }
ol { break-inside: avoid; page-break-inside: avoid; }
table { width: 100%; border-collapse: collapse; margin: 6pt 0 10pt; font-size: 9.5pt; break-inside: avoid; page-break-inside: avoid; }
th, td { border: 1px solid #A9B2AA; padding: 3pt 5pt; text-align: left; vertical-align: top; }
th { background: #E3E7E1; }
tr { break-inside: avoid; page-break-inside: avoid; }
code { font-family: Consolas, "Courier New", monospace; font-size: 9.5pt; background: #f1f2ef; padding: 0 2pt; border-radius: 2pt; }
blockquote { margin: 8pt 0; padding: 6pt 10pt; border-left: 4px solid #BE3A2B; background: #fbf3f1; break-inside: avoid; page-break-inside: avoid; }
blockquote p { margin: 0 0 4pt; }
hr { display: none; }
img.shot { display: block; max-width: 100%; max-height: 225mm; margin: 6pt auto 10pt; border: 1px solid #c9cec8; break-inside: avoid; page-break-inside: avoid; }
img.shot.phone { max-width: 60%; max-height: 215mm; }

.cover { height: 297mm; padding: 40mm 22mm 24mm; display: flex; flex-direction: column; align-items: center; text-align: center; break-after: page; page-break-after: always; }
.cover img { width: 46mm; height: auto; margin-top: 20mm; }
.cover h1 { font-size: 26pt; margin: 18mm 0 6mm; }
.cover .sub { font-size: 12pt; color: #5C6A62; }
.cover .meta { margin-top: auto; font-size: 10.5pt; color: #5C6A62; }

.contents h2 { break-before: auto; page-break-before: auto; }
.contents ol { list-style: none; padding: 0; break-inside: auto; page-break-inside: auto; }
.contents li { margin: 0; }
.contents .toc-2 { font-weight: 600; margin-top: 5pt; }
.contents .toc-3 { padding-left: 14pt; font-size: 10pt; }
.contents a { text-decoration: none; }
.intro { break-before: page; page-break-before: always; }
`;

(async () => {
    const markdown = fs.readFileSync(SOURCE, 'utf8');
    const { body, contents } = render(markdown);
    const logo = dataUri(LOGO);
    const today = new Date().toLocaleDateString('en-AU', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Australia/Brisbane' });
    const ver = version();

    const html = `<!doctype html><html lang="en-AU"><head><meta charset="utf-8"><title>${TITLE}</title><style>${css}</style></head><body>
<section class="cover">
  <img src="${logo}" alt="Local Selection">
  <h1>${TITLE}</h1>
  <div class="sub">For warehouse staff, managers, purchasing and administrators</div>
  <div class="meta">Version ${ver} · ${today}</div>
</section>
<nav class="contents"><h2 id="contents">Contents</h2><ol>${contents}</ol></nav>
<div class="intro">${body}</div>
</body></html>`;

    const header = `<div style="width:100%;padding:0 18mm;display:flex;align-items:center;justify-content:space-between;font-family:'Segoe UI',Arial,sans-serif;font-size:8pt;color:#5C6A62;">
  <img src="${logo}" style="height:8mm;width:auto;"><span>${TITLE}</span></div>`;
    const footer = `<div style="width:100%;padding:0 18mm;text-align:center;font-family:'Segoe UI',Arial,sans-serif;font-size:8pt;color:#5C6A62;">
  Page <span class="pageNumber"></span> of <span class="totalPages"></span></div>`;

    const browser = await chromium.launch();
    const page = await browser.newPage();
    await page.setContent(html, { waitUntil: 'load' });
    await page.emulateMedia({ media: 'print' });
    const printed = await page.pdf({
        format: 'A4',
        preferCSSPageSize: true,
        printBackground: true,
        displayHeaderFooter: true,
        headerTemplate: header,
        footerTemplate: footer,
        outline: true,
        tagged: true,
    });
    await browser.close();

    // Chromium prints the header and footer on every page; blank them on the cover only.
    // Painting over (rather than splitting and re-joining PDFs) keeps the links and page numbers.
    const pdf = await PDFDocument.load(printed);
    const cover = pdf.getPage(0);
    const { width, height } = cover.getSize();
    const band = 18 * (72 / 25.4);
    cover.drawRectangle({ x: 0, y: height - band, width, height: band, color: rgb(1, 1, 1) });
    cover.drawRectangle({ x: 0, y: 0, width, height: band, color: rgb(1, 1, 1) });
    pdf.setTitle(TITLE);
    pdf.setAuthor('Local Selection');
    pdf.setSubject(`Version ${ver}`);
    fs.writeFileSync(OUT, await pdf.save());
    console.log(`Wrote ${path.relative(ROOT, OUT)} (version ${ver}, ${(fs.statSync(OUT).size / 1048576).toFixed(1)} MB)`);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
