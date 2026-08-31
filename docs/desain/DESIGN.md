---
name: Precision Ledger
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
  surface-tint: '#0053db'
  primary: '#004ac6'
  on-primary: '#ffffff'
  primary-container: '#2563eb'
  on-primary-container: '#eeefff'
  inverse-primary: '#b4c5ff'
  secondary: '#515f74'
  on-secondary: '#ffffff'
  secondary-container: '#d5e3fc'
  on-secondary-container: '#57657a'
  tertiary: '#943700'
  on-tertiary: '#ffffff'
  tertiary-container: '#bc4800'
  on-tertiary-container: '#ffede6'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#dbe1ff'
  primary-fixed-dim: '#b4c5ff'
  on-primary-fixed: '#00174b'
  on-primary-fixed-variant: '#003ea8'
  secondary-fixed: '#d5e3fc'
  secondary-fixed-dim: '#b9c7df'
  on-secondary-fixed: '#0d1c2e'
  on-secondary-fixed-variant: '#3a485b'
  tertiary-fixed: '#ffdbcd'
  tertiary-fixed-dim: '#ffb596'
  on-tertiary-fixed: '#360f00'
  on-tertiary-fixed-variant: '#7d2d00'
  background: '#faf8ff'
  on-background: '#191b23'
  surface-variant: '#e1e2ed'
  cash-blue: '#2563EB'
  hutang-amber: '#D97706'
  lunas-green: '#16A34A'
  danger-red: '#DC2626'
  special-teal: '#0D9488'
  surface-muted: '#F8FAFC'
typography:
  headline-lg:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '700'
    lineHeight: 32px
    letterSpacing: -0.02em
  headline-md:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  headline-sm:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 24px
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
    letterSpacing: 0.05em
  mono-data:
    fontFamily: JetBrains Mono
    fontSize: 13px
    fontWeight: '500'
    lineHeight: 16px
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  unit: 4px
  gutter: 16px
  margin-page: 24px
  table-cell-x: 12px
  table-cell-y: 8px
---

## Brand & Style

The design system is engineered for high-stakes business environments where data integrity, speed of entry, and financial clarity are paramount. The brand personality is **Professional, Utilitarian, and Precise**, catering to "Super Kasir" power users and administrators who manage large inventories and complex transaction histories.

The chosen style is **Corporate Modern with a Data-Centric Focus**. It emphasizes a flat, clean dashboard aesthetic that prioritizes content over container. By utilizing generous whitespace and a rigorous hierarchy, the system reduces cognitive load during mass data entry. The visual language is inspired by systematic tools like Bloomberg or modern ERPs, ensuring that critical alerts (like "Stok Habis") are immediately visible without being visually distracting.

Key principles:
- **Efficiency over Decoration:** Every element must serve a functional purpose.
- **Immediate Legibility:** High contrast between text and backgrounds.
- **Safety through Color:** A semantic-first approach to color to prevent user error.

## Colors

The palette is anchored by a **Trustworthy Blue** and **Slate Gray**, providing a neutral foundation that allows semantic alerts to stand out. 

- **Primary (Blue):** Used for primary actions, active navigation states, and the 'Cash' transaction type.
- **Secondary (Slate):** Used for structural elements, secondary buttons, and less critical information.
- **Semantic Mapping:**
    - **Cash (Blue):** Standard liquidity.
    - **Hutang (Amber):** Pending/Debt states; requires attention.
    - **Lunas (Green):** Success; completed transactions.
    - **Warning/Stok Habis (Red):** Critical failure or exhaustion of resources.
- **Neutral:** A range of grays from `#F8FAFC` for page backgrounds to `#0F172A` for primary text.

All colored status badges should use a high-contrast ratio. For rows where "Jatuh Tempo" (Due Date) has passed, a subtle background tint of `#FEF2F2` (Red-50) with a `#DC2626` (Red-600) left-border accent is used.

## Typography

The system uses **Inter** for its exceptional legibility in dense data tables and interface controls. 

- **Data Tables:** Use `table-data` for standard entries. For `KODE_BARANG`, `NO_SN`, and numerical financial figures, use a monospaced font (JetBrains Mono) to ensure character alignment, which is critical for scanning lists for discrepancies.
- **Hierarchy:** Page titles use `headline-lg` in Slate-900. Section headers use `headline-sm`.
- **Status Badges:** Use `label-caps` for high-visibility status tags (e.g., "DIJUAL").
- **Scale:** On mobile, `headline-lg` scales down to 20px (`headline-md`) to ensure titles do not wrap aggressively.

## Layout & Spacing

This design system utilizes a **12-column fluid grid** for desktop and a single-column layout for mobile. 

- **Spacing Rhythm:** Based on a 4px baseline. Most components use 8px or 16px increments.
- **Information Density:** The layout is "comfortable-dense." Table cells use `12px` horizontal padding to allow for more columns while maintaining 8px vertical padding for touch-friendliness on tablets.
- **Form Layouts:** Input fields are grouped into logical sections (e.g., "Info Toko" vs. "Parameters") using a 2-column layout on desktop, collapsing to 1-column on mobile.
- **Breakpoints:**
    - Mobile: < 640px (Margins: 16px)
    - Tablet: 640px - 1024px (Margins: 20px)
    - Desktop: > 1024px (Margins: 24px, Fixed Sidebar: 240px)

## Elevation & Depth

To maintain a "Modern Dashboard" aesthetic, the system avoids heavy shadows in favor of **Tonal Layers** and **Low-Contrast Outlines**.

- **Level 0 (Background):** Slate-50 (`#F8FAFC`).
- **Level 1 (Cards/Base):** White (`#FFFFFF`) with a 1px border in Slate-200. No shadow.
- **Level 2 (Dropdowns/Modals):** White with a soft, diffused ambient shadow (`0 10px 15px -3px rgba(0, 0, 0, 0.1)`).
- **Interactive States:** Buttons and inputs use a subtle 1px inner shadow on hover to simulate a slight "press" without breaking the flat aesthetic.
- **Read-Only State:** Fields like "Sisa Hutang" use a Slate-100 background with a Slate-300 border to clearly communicate non-interactivity.

## Shapes

The shape language is **Soft and Professional**. 

- **Standard (0.25rem):** Applied to input fields, standard buttons, and table rows. This minimal rounding maintains a technical, "data-first" feel.
- **Large (0.5rem):** Applied to Dashboard metric cards and Modals to differentiate them from functional inputs.
- **Pill (Full):** Exclusively reserved for status badges (e.g., "Lunas", "Belum Lunas") and toggle indicators to make them instantly recognizable as non-interactive status markers or distinct toggles.

## Components

### Data Tables
- **Header:** Sticky top with a Slate-100 background.
- **Rows:** Alternating zebra stripes are not used; instead, use 1px Slate-100 bottom borders. Highlight rows in Red-50 for expired "Jatuh Tempo."
- **Actions:** Icons (Pencil, Trash, Eye) should be grouped in a right-aligned column with consistent 16px spacing.

### Buttons & Toggles
- **Primary Button:** Solid Blue-600, Rounded (4px).
- **Transaction Toggle:** A segmented control (Cash/Hutang). The active state uses a solid background (Blue for Cash, Amber for Hutang) with white text; the inactive state uses a Slate-100 background.

### Input Fields
- **Validation:** Clear Red-600 borders for error states with 11px helper text below the field.
- **Labels:** Always positioned above the input. Use "Otomatis" or "Read-only" watermarks for auto-filled data.

### Dashboard Cards
- **Metrics:** Bold numerical figures in Blue-700 with a small trend indicator or label below. 
- **Alert Cards:** "Stok Menipis" cards should feature a Red-600 left-accent border to draw immediate attention.

### Modals
- **Context:** Use for "Tambah Supplier" or "Quick View." Modals should be centered with a 40% opacity black backdrop blur to keep the user focused on the atomic task.