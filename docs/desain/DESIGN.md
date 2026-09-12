---
name: Precision Ledger
version: 3
colors:
  surface: '#faf8ff'
  surface-dim: '#d9d9e5'
  surface-bright: '#faf8ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f3f3fe'
  surface-container: '#ededf9'
  surface-container-high: '#e7e7f3'
  surface-container-highest: '#e1e2ed'
  on-surface: '#191b23'
  on-surface-variant: '#434655'
  inverse-surface: '#2e3039'
  inverse-on-surface: '#f0f0fb'
  outline: '#737686'
  outline-variant: '#c3c6d7'
  surface-tint: '#7c3aed'
  primary: '#6d28d9'
  on-primary: '#ffffff'
  primary-container: '#6d28d9'
  on-primary-container: '#f5f0ff'
  primary-fixed: '#dbe1ff'
  on-primary-fixed: '#00174b'
  on-primary-fixed-variant: '#003ea8'
  inverse-primary: '#b4c5ff'
  secondary: '#3f4756'
  on-secondary: '#ffffff'
  secondary-container: '#d5e3fc'
  on-secondary-container: '#57657a'
  secondary-fixed: '#d5e3fc'
  on-secondary-fixed: '#0d1c2e'
  on-secondary-fixed-variant: '#3a485b'
  tertiary: '#a21caf'
  on-tertiary: '#ffffff'
  tertiary-container: '#d946ef'
  on-tertiary-container: '#ffede6'
  tertiary-fixed: '#ffdbcd'
  on-tertiary-fixed: '#360f00'
  on-tertiary-fixed-variant: '#7d2d00'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  background: '#faf8ff'
  on-background: '#191b23'
  surface-variant: '#e1e2ed'
  cash-blue: '#2563EB'
  hutang-amber: '#D97706'
  lunas-green: '#16A34A'
  danger-red: '#DC2626'
  special-teal: '#0D9488'
  surface-muted: '#F8FAFC'
colors-dark:
  surface: '#1a1b23'
  surface-dim: '#0d0e14'
  surface-bright: '#1a1b23'
  surface-container-lowest: '#1e1f28'
  surface-container-low: '#242530'
  surface-container: '#2a2b36'
  surface-container-high: '#30313c'
  surface-container-highest: '#363742'
  on-surface: '#e8e8f0'
  on-surface-variant: '#a0a1b2'
  inverse-surface: '#e8e8f0'
  inverse-on-surface: '#1a1b23'
  outline: '#5a5b6e'
  outline-variant: '#3a3b48'
  primary: '#8b5cf6'
  surface-tint: '#a78bfa'
  primary-container: '#6d28d9'
  on-primary-container: '#f5f0ff'
  secondary: '#a0b0cc'
  secondary-container: '#3a4b66'
  on-secondary-container: '#b8c8e4'
  tertiary: '#c026d3'
  tertiary-container: '#a21caf'
  error: '#fca5a5'
  on-error: '#1a1b23'
  error-container: '#5c2020'
  cash-blue: '#5b8def'
  hutang-amber: '#f0b040'
  lunas-green: '#4ade80'
  danger-red: '#f87171'
  special-teal: '#2dd4bf'
  surface-muted: '#12131a'
typography:
  headline-lg:
    fontFamily: Inter
    fontSize: 26px
    fontWeight: '700'
    lineHeight: 34px
    letterSpacing: -0.02em
  headline-md:
    fontFamily: Inter
    fontSize: 21px
    fontWeight: '600'
    lineHeight: 30px
  headline-sm:
    fontFamily: Inter
    fontSize: 17px
    fontWeight: '600'
    lineHeight: 26px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  body-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  table-data:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 16px
  label-caps:
    fontFamily: Inter
    fontSize: 11px
    fontWeight: '700'
    lineHeight: 16px
    letterSpacing: 0.08em
  mono-data:
    fontFamily: JetBrains Mono
    fontSize: 13px
    fontWeight: '500'
    lineHeight: 16px
rounded:
  sm: 0.375rem
  DEFAULT: 0.5rem
  md: 0.625rem
  lg: 0.875rem
  xl: 1.25rem
  full: 9999px
shadows:
  card: '0 1px 2px rgba(16,24,64,.06), 0 4px 16px -4px rgba(67,56,202,.10)'
  pop: '0 12px 32px -8px rgba(67,56,202,.22)'
  glow-primary: '0 0 0 1px rgba(124,58,237,.40), 0 8px 28px -6px rgba(124,58,237,.50)'
  glow-tertiary: '0 0 0 1px rgba(217,70,239,.35), 0 8px 28px -6px rgba(217,70,239,.45)'
gradients:
  brand: 'linear-gradient(135deg, #7c3aed, #4f46e5 55%, #0e7490)'
spacing:
  unit: 4px
  gutter: 16px
  margin-page: 24px
  table-cell-x: 12px
  table-cell-y: 8px
  card-pad: 24px
  content-max: 1400px
---

## Brand & Style

The design system is engineered for high-stakes business environments where data integrity, speed of entry, and financial clarity are paramount. The brand personality is **Professional, Utilitarian, and Precise**, catering to "Super Kasir" power users and administrators who manage large inventories and complex transaction histories.

The chosen style is **Corporate Modern with a Data-Centric Focus**. It emphasizes a flat, clean dashboard aesthetic that prioritizes content over container. By utilizing generous whitespace and a rigorous hierarchy, the system reduces cognitive load during mass data entry. The visual language is inspired by systematic tools like Bloomberg or modern ERPs, ensuring that critical alerts (like "Stok Habis") are immediately visible without being visually distracting.

Key principles:
- **Efficiency over Decoration:** Every element must serve a functional purpose.
- **Immediate Legibility:** High contrast between text and backgrounds.
- **Safety through Color:** A semantic-first approach to color to prevent user error.

Bahasa antarmuka: **Indonesia**. Menu navigasi mengikuti PRD (Dashboard, Data Barang, Tracking Stok, Data Supplier, Pembelian, Penjualan, Generate Transaksi, Export, Pengaturan, Backup).

## Colors

The palette is anchored by an **Electric Violet** (`#6D28D9`) and **Ink Slate** (`#3F4756`), with a **Fuchsia** tertiary (`#A21CAF`) — the "Volt" direction (Batch E). The neutral foundation lets semantic alerts stand out; the violet-fuchsia axis carries brand moments (primary buttons, active nav, KPI accents).

- **Primary (Violet):** Used for primary actions, active navigation states, and gradient brand moments. White text on primary = 7.1:1 (AA).
- **Secondary (Ink Slate):** Used for structural elements, secondary buttons, and body text (8.9:1 on page background).
- **Tertiary (Fuchsia):** Tertiary actions and accents. 6.3:1 vs white, 4.9:1 on `tertiary-fixed` wash.
- **Gradient brand (`--gradient-brand` "V1 Volt"):** `linear-gradient(135deg, #7C3AED, #4F46E5 55%, #0E7490)`. All stops + midpoints ≥ 5.3:1 vs white — safe for white semibold button text. Used for: `btn-primary` background, `brand-tile` (sidebar logo), `nav-link-active` edge bar, `kpi-card` top line.
- **Fixed colors (`primary-fixed`, `secondary-fixed`, `tertiary-fixed`):** Tonal fills for icon tiles, avatars, and decorative washes. Always pair with the matching `on-*-fixed` text color. (Previously missing from the CSS theme — v2 canonicalizes them; see bug note in A2.)
- **`surface-tint`:** Hover darkening for primary surfaces (e.g. primary button hover).
- **Semantic Mapping:**
    - **Cash (Blue):** Standard liquidity.
    - **Hutang (Amber):** Pending/Debt states; requires attention.
    - **Lunas (Green):** Success; completed transactions.
    - **Warning/Stok Habis (Red):** Critical failure or exhaustion of resources.
- **Neutral:** A range of grays from `#F8FAFC` for page backgrounds to `#0F172A` for primary text.

All colored status badges should use a high-contrast ratio. For rows where "Jatuh Tempo" (Due Date) has passed, a subtle background tint of `#FEF2F2` (Red-50) with a `#DC2626` (Red-600) left-border accent is used.

## Typography

The system uses **Inter** for its exceptional legibility in dense data tables and interface controls. Loaded via Google Fonts (weights 400/500/600/700/900) in `layouts/app.blade.php`; print layout loads Inter 400/600/700 + JetBrains Mono 400/500.

- **Data Tables:** Use `table-data` for standard entries. For `KODE_BARANG`, `NO_SN`, and numerical financial figures, use a monospaced font (JetBrains Mono) to ensure character alignment, which is critical for scanning lists for discrepancies.
- **Hierarchy:** Page titles use `headline-lg` in Slate-900. Section headers use `headline-sm`.
- **Status Badges:** Use `label-caps` for high-visibility status tags (e.g., "DIJUAL").
- **Scale:** On mobile, `headline-lg` scales down to 20px (`headline-md`) to ensure titles do not wrap aggressively.
- **Icons:** Material Symbols Outlined (`material-symbols-outlined`); decorative icons get `aria-hidden="true"`.

## Layout & Spacing

This design system utilizes a **12-column fluid grid** for desktop and a single-column layout for mobile. Content column is capped at `content-max: 1400px` and centered.

- **Spacing Rhythm:** Based on a 4px baseline. Most components use 8px or 16px increments.
- **Information Density:** The layout is "comfortable-dense." Table cells use `12px` horizontal padding to allow for more columns while maintaining 8px vertical padding for touch-friendliness on tablets.
- **Form Layouts:** Input fields are grouped into logical sections (e.g., "Info Toko" vs. "Parameters") using a 2-column layout on desktop, collapsing to 1-column on mobile.
- **Page head (`page-head`):** Every index/detail page opens with title (`headline-lg`) + subtitle (`body-md text-secondary`) on the left and contextual actions on the right; stacks vertically on mobile.
- **Breakpoints:**
    - Mobile: < 640px (Margins: 16px)
    - Tablet: 640px - 1024px (Margins: 20px)
    - Desktop: > 1024px (Margins: 24px, Fixed Sidebar: 240px)

## Sidebar & Topbar

- **Sidebar:** Fixed 240px (`bg-surface-container-low`, right border `outline-variant`). Brand block: 40px gradient `brand-tile` with `build` icon + "ReconPOS" wordmark + caption ("Automated Sales Reconstruction", or "Mode Publik — Lihat Saja" in public mode). Section micro-labels (`label-caps`, e.g. "MENU UTAMA", "ADMIN") group items. Active item uses `nav-link-active` (solid container bg + gradient edge bar + `aria-current="page"`). Bottom slot: Logout form (admin) or Login link (public).
- **Rail mode:** `body.sidebar-minimized` collapses the sidebar to a 64px icon rail on desktop (labels hidden, icons centered); main content margin follows. Toggled via `#sidebar-toggle` on desktop.
- **Mobile drawer:** Below 768px the sidebar is off-canvas (`-translate-x-full`) with a `bg-black/40` overlay (`#sidebar-overlay`, no backdrop blur to keep content sharp); `#sidebar-toggle` opens it.
- **Theme toggle:** `#theme-toggle` in the brand block swaps `.dark` on `<html>` and persists to `localStorage('theme')`; icons `dark_mode`/`light_mode` swap via CSS-only classes (no flash).
- **Topbar:** Sticky 64px, glass (`bg-surface-container-lowest/85` + `backdrop-blur-md`; dark: `rgba(30,31,40,.85)` + blur), bottom border. Left: drawer/rail toggle + page/shop title (`headline-md text-primary`). Right: role badge (`Admin` / `Mode Publik`, pill `secondary-container`) + identity avatar (admin initial tile, or `public` icon tile in public mode).

## Dark Mode

Toggled via `.dark` on `<html>` (persisted in `localStorage`, OS preference as default). All semantic colors have dark equivalents in `colors-dark` above: surfaces shift to `#12131a–#363742`, text to `#e8e8f0/#a0a1b2`, primary lifts to `#8b5cf6` for contrast, semantic hues shift to their 300–400 range (`lunas-green #4ade80`, `danger-red #f87171`, `hutang-amber #f0b040`, `cash-blue #5b8def`, `special-teal #2dd4bf`). Glow shadows are stronger in dark mode (neon reads better on dark backgrounds).

Rules:
- Never hardcode light-only utilities (`bg-white`, `bg-slate-50`, `bg-red-50`…) for meaningful surfaces — the theme ships `.dark` overrides for legacy cases, but new markup must use token classes (`bg-surface-container-lowest`, `text-secondary`, `border-outline-variant`, `badge-*`).
- Tint washes in dark mode use 12–20% alpha fills (not solid pastels); text on washes uses the 200–300 tint of the same hue.
- Charts/prints always render light (see Print).

## Elevation & Depth

Cards carry a soft violet-tinted shadow (`--shadow-card`); modals/dropdowns a deeper pop (`--shadow-pop`). Glow (`--shadow-glow-primary/-tertiary`) is reserved for hover/focus on primary actions and active states — stronger in dark mode.

- **Level 0 (Background):** Slate-50 (`#F8FAFC`).
- **Level 1 (Cards/Base):** White (`#FFFFFF`) with a 1px border in Slate-200 + `shadow-card`.
- **Level 2 (Dropdowns/Modals):** White with `shadow-pop`.
- **Interactive States:** `btn-primary:hover`/`focus-visible` lifts brightness + glow. Inputs keep the primary focus ring.
- **Read-Only State:** Fields like "Sisa Hutang" use a Slate-100 background with a Slate-300 border to clearly communicate non-interactivity.
- **Modal:** `modal-backdrop` (fixed inset, `bg-black/40`, `backdrop-blur-sm`, z-40) + `modal-card` (`card p-6` + `shadow-pop`, max-w-lg, centered).

## Shapes

The shape language is **Soft and Professional** — Batch E rounds every step up one notch (12px basis).

- **Small (0.375rem):** Input fields, standard buttons.
- **Medium (0.625rem):** Segmented controls, small containers.
- **Large (0.875rem):** Modals, toggles.
- **XL (1.25rem):** Cards (`card`, `card-pad`), page-level containers, alert panels.
- **Pill (Full):** Exclusively reserved for status badges (e.g., "Lunas", "Belum Lunas") and toggle indicators to make them instantly recognizable as non-interactive status markers or distinct toggles.

## Components

### Page Head
`page-head`: flex row (title + subtitle left, actions right; stacks on mobile). Title `headline-lg text-on-surface`; subtitle `body-md text-secondary`.

### Data Tables
- **Header:** Sticky top with a Slate-100 background.
- **Rows:** Alternating zebra stripes are not used; instead, use 1px Slate-100 bottom borders. Highlight rows in Red-50 for expired "Jatuh Tempo."
- **Actions:** Icons (Pencil, Trash, Eye) should be grouped in a right-aligned column with consistent 16px spacing.
- **Toolbar (`partials/table-toolbar`):** Actions left; search (`q`), `entries` select (10/25/50/100 + "entri"), Reset right.
- **Bulk hooks (frozen):** Row checkboxes use `.bulk-check[data-bulk="{scope}"]`, select-all `#bulk-all-{scope}`, counter `#bulk-print-count-{scope}`, delete button `[data-bulk-delete-btn="{scope}"]`, print button `#bulk-print-btn-{scope}`. Never rename — JS in `partials/bulk-print` and tests depend on them.

### KPI Cards
`kpi-card`: white/level-1 card, `label-caps` caption, `headline-lg` value (mono for currency), footnote row. Every card has a 3px gradient top line (`::before`, brand gradient). Semantic left-accent variants: `kpi-accent-red` (danger), `kpi-accent-amber` (warning), `kpi-accent-blue`, `kpi-accent-green`. Alert cards ("Stok Menipis") use the red accent. Opt-in `.kpi-live` animates the top line (`gradient-shift`, 5s, alternate) — small elements only; disabled under `prefers-reduced-motion`.

### Alert Panel
`alert-panel`: list of attention items; each item is a tinted row (red-50/amber-50 + matching border) with icon, bold title, and detail line.

### Buttons & Toggles
- **Primary Button:** Gradient brand background (`--gradient-brand`, white semibold text — all stops ≥ 5.3:1). Hover/focus lifts brightness + glow.
- **Transaction Toggle (segmented):** Container `segmented` carries `shadow-card`. Inactive segments turn `on-surface` on hover. The active state keeps its semantic solid fill (Blue = Cash, Amber = Hutang, Green = Lunas) + glossy top sheen (`inset 0 1px 0 rgba(255,255,255,.25)`) + glow shadow in the same hue. Semantic hues are never replaced by the brand gradient here — color carries meaning (safety). The wrapper `.seg-bar` (e.g. nav-tabs pembelian) is a glass command bar: translucent surface + blur + `shadow-card` + gradient edge bar, echoing `nav-link-active`.
- **Icon buttons (`.btn-icon`):** 32px tile for row actions and toolbar icons.

### Badges
Pill, `label-caps` (normal case in running text). Variants: `badge-green` (Lunas/success), `badge-amber` (Hutang/pending), `badge-red` (danger/overdue), `badge-blue` (info/Cash), `badge-teal` (special), `badge-slate` (neutral). Soft wash variants `badge-red-soft`/`badge-amber-soft` use design tokens (dark-safe) for inline alerts.

### Input Fields
- **Validation:** Clear Red-600 borders for error states with 11px helper text below the field.
- **Labels:** Always positioned above the input. Use "Otomatis" or "Read-only" watermarks for auto-filled data.

### Flash Messages
Success (green wash + `check_circle`), error (red wash + `error`), validation list (`error-container` wash + `warning`). All carry `role="alert"`. Text is always escaped (`{{ }}`).

### Dashboard Cards
- **Metrics:** Bold numerical figures in Blue-700 with a small trend indicator or label below.
- **Alert Cards:** "Stok Menipis" cards should feature a Red-600 left-accent border to draw immediate attention.
- **Quick actions:** 3-up icon tiles (primary/secondary/tertiary fixed washes) linking to core tasks; hidden in public mode.

### Modals
- **Context:** Use for "Tambah Supplier" or "Quick View." Modals should be centered with a 40% opacity black backdrop blur to keep the user focused on the atomic task. Use `modal-backdrop` + `modal-card`.

### Empty State
`empty-state`: centered icon tile + headline-sm title + body-sm hint (+ optional action). Replaces bare "Tidak ada data" table rows at page level; inside tables keep the `py-10 text-center text-secondary` row pattern.

## Motion & Responsiveness (Batch F)

- **Transitions:** `.btn`, `.btn-icon`, `.badge` use `transition-all duration-200 ease-out`; `.card`/`.card-pad`/`.kpi-card` transition shadow + border-color.
- **Entrance:** `.animate-rise` (fade + 10px rise, 0.45s; delay via `--rise-delay`) for sections; `tbody.rise-rows` staggers rows ~30ms up to 10 rows.
- **Loading:** JS adds `.is-loading` (spinner + disabled) to POST submit buttons; skipped for GET, `data-delete-ajax`, and `data-no-loading` forms; 10s re-enable fallback.
- **Toast:** `partials/flash` blocks carry `data-toast` (slide-in) + `.toast-bar` 6s progress; JS auto-dismisses at 6s.
- **Modal:** `.modal-backdrop` fades, `.modal-card` pops (scale + rise). On mobile (<640px) the card becomes a bottom sheet.
- **Tables on mobile:** `table.tbl.tbl-cards` collapses to label:value cards below 640px (labels from `data-label`; `No` hidden; `Aksi` right-aligned without label). Desktop keeps the dense table.
- **Forms on mobile:** single column, full-width inputs; `.form-actions-sticky` pins the action bar to the viewport bottom.
- **Reduced motion:** all of the above (plus the Batch E gradient) is disabled under `prefers-reduced-motion: reduce`.
- **Sidebar drawer:** `#sidebar-toggle` opens the off-canvas sidebar below 768px; tap on `#sidebar-overlay` closes it (JS in `app.js`).

## Print

Print views use `layouts/print.blade.php` (standalone, no app shell): max-width 640px invoice, Inter + JetBrains Mono (print-safe font links), uppercase micro-headers, `.total-row` emphasis, dashed `.barcode-label`.

Frozen print contract (JS + tests depend on it):
- `.print-area .print-page { page-break-after: always }`; `.pb-off` disables it; last page never breaks; `.print-page { padding: 24px 0 }`.
- `.bulk-controls` fixed panel with `#pb-toggle` ("Halaman baru per nota"); hidden in `@media print`.
- Hook ids/classes `pb-toggle`, `bulk-controls`, `bulk-check` must never be renamed.
- Print always renders light (`background: #fff`), regardless of dark mode.
- **Ban:** no gradients, glow shadows, or animations inside `.print-page`/`.invoice` — print stays solid, print-safe colors only. Data tables (`.tbl`) stay high-contrast with no animation anywhere.
