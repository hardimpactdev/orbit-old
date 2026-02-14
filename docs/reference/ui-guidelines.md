# UI/Design Guidelines

## Tech Stack

- **Frontend**: Vue 3 + TypeScript + Inertia.js
- **Styling**: Tailwind CSS
- **Icons**: Lucide Vue Next

## Color Palette

- **Background**: `bg-zinc-900` (page), `bg-zinc-800/30` (cards/rows)
- **Borders**: `border-zinc-800` (outer), `border-zinc-700/50` (inner/subtle)
- **Text**: `text-white` (primary), `text-zinc-400` (secondary), `text-zinc-500` (muted)
- **Accent**: `text-lime-400` / `bg-lime-500` (success/primary action)
- **Danger**: `text-red-400` / `bg-red-400/10`
- **Warning**: `text-amber-400` / `bg-amber-400/10`

## Page Layouts

**Dashboard Pages** (Show.vue, Index pages):

- Use card structure with outer border and inner content areas
- Outer card: `border border-zinc-800 rounded-xl px-0.5 pt-4 pb-0.5`
- Section header: `px-4 mb-4` with title and optional action button
- Inner content: `border border-zinc-700/50 rounded-lg overflow-hidden`
- Content rows: `bg-zinc-800/30` with `space-y-px` for 1px gaps

**Form Pages** (Create.vue, Edit.vue):

- Simple layout with horizontal dividers, NO outer card border
- Two-column grid: `grid grid-cols-2 gap-8 py-6`
- Left column: Label + description
- Right column: Input field
- Section dividers: `<hr class="border-zinc-800" />`

## Tables

```vue
<table class="table-catalyst w-full border-separate" style="border-spacing: 0 2px;">
    <thead>
        <tr class="bg-zinc-800/30">
            <th class="px-4 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider rounded-l-lg">Column</th>
            <!-- middle columns without rounded -->
            <th class="px-4 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wider rounded-r-lg">Actions</th>
        </tr>
    </thead>
    <tbody>
        <tr class="bg-zinc-800/30 hover:bg-zinc-700/30">
            <td class="px-4 py-3 rounded-l-lg">Content</td>
            <!-- middle columns -->
            <td class="px-4 py-3 text-right rounded-r-lg">Actions</td>
        </tr>
    </tbody>
</table>
```

Key table patterns:

- `border-separate` with `border-spacing: 0 2px` for row gaps
- Header AND data rows have same `bg-zinc-800/30` background
- First cell: `rounded-l-lg`, last cell: `rounded-r-lg`
- Header text: `text-xs font-medium text-zinc-500 uppercase tracking-wider`
- Hover state: `hover:bg-zinc-700/30`

## Buttons

```vue
<!-- Primary action (lime) -->
<button class="btn btn-secondary">Action</button>

<!-- Outline/secondary -->
<button class="btn btn-outline">Action</button>

<!-- Plain/text button -->
<button class="btn btn-plain">Cancel</button>

<!-- Small button -->
<button class="btn btn-secondary py-1 px-2 text-xs">Small</button>
```

## Form Inputs

- All inputs inherit base styling from `app.css`
- Use `font-mono` for paths, URLs, technical values
- Select dropdowns: `class="text-xs py-1 pl-2 pr-7"` for compact version

## Badges

```vue
<span class="badge badge-zinc">Label</span>
<span
    class="text-xs px-2 py-0.5 rounded-full bg-lime-400/10 text-lime-400 border border-lime-400/20"
>Status</span>
```

## Loading States

```vue
<Loader2 class="w-4 h-4 animate-spin" />
```

## Icons

- Standard size: `w-4 h-4`
- Small (in buttons): `w-3.5 h-3.5`
- Always import from `lucide-vue-next`

## Spacing

- Section margin: `mb-6`
- Inner padding: `p-5` or `px-4 py-3`
- Gap between items: `gap-2` or `gap-3`

## Do NOT

- Add emojis unless explicitly requested
- Create new files unless necessary (prefer editing existing)
- Over-engineer or add unnecessary abstractions
- Add features beyond what was asked
