---
date: 2026-02-18
problem_type: browser-automation
component: vue-frontend
severity: medium
symptoms:
  - Playwright fill/type actions don't update form values
  - DOM shows value but Vue model is empty
  - Form submission fails validation
root_cause: Vue uses reactive proxies; DOM value changes bypass reactivity layer
tags: [vue, playwright, browser-automation, forms, reactivity]
source_instance: mini
---

# Vue Form Inputs: DOM Manipulation Doesn't Work

**Context:** Orbit setup form at `127.0.0.1:8100/setup`. Browser `fill`/`type` actions didn't update the Vue reactive model.

## Problem

Vue components don't detect changes made by:
- `input.value = "something"` (direct DOM manipulation)
- Playwright/browser `type` or `fill` actions that set `value`
- Any method that bypasses Vue's event system

## Root Cause

Vue uses its own reactive system (getter/setter proxies on component data). DOM value changes bypass the Vue reactivity layer entirely. The Vue model stays empty even though the DOM shows the value.

## Symptoms

1. Browser automation "fills" the form field
2. Visual inspection shows the value in the input
3. Form submission fails or submits empty values
4. Vue devtools show empty model data

## Solutions

### Option 1: Use the API Directly (Recommended)

Find the underlying API endpoint and POST directly:

```bash
curl -s -X POST http://127.0.0.1:8100/setup/run \
  -H "Content-Type: application/json" \
  -d '{"tld": "mini", "other_field": "value"}'
```

**Advantages:**
- Bypasses frontend entirely
- More reliable than browser automation
- Faster execution

### Option 2: Trigger Vue Events

If you must use browser automation, trigger Vue's event system:

```javascript
// After setting value, dispatch input event
input.value = "something";
input.dispatchEvent(new Event('input', {bubbles: true}));
```

Or in Playwright:
```javascript
await page.fill('input[name="field"]', 'value');
await page.dispatchEvent('input[name="field"]', 'input');
```

**Note:** This may not work for all Vue form libraries (Vuetify, Element Plus, etc.)

### Option 3: Use Vue Test Utils

For testing, use Vue-specific tools instead of generic browser automation.

## Prevention

When planning browser automation:
1. Check if target uses Vue/React/Angular
2. If yes, prefer API calls over form filling
3. If form filling required, test event dispatch

## Related

- Mini's browser automation capabilities
- Orbit E2E testing infrastructure
