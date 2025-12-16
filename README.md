# Chapter Navigation for StaticForge

**What it does:** Automatically generates sequential prev/next navigation links for documentation pages based on menu ordering.

## Installation

```bash
composer require calevans/staticforge-chapternav
php vendor/bin/staticforge feature:setup calevans/staticforge-chapternav
```

## Configuration

Set via `siteconfig.yaml` file.

```yaml
# Chapter Navigation Configuration
chapter_nav:
  menus: "2"
  prev_symbol: "←"
  next_symbol: "→"
```

### Disabling Chapter Navigation

To completely disable chapter navigation processing, either:
- Set `menus: ""` (empty string) in your `siteconfig.yaml`
- Remove the `chapter_nav` section from `siteconfig.yaml` and ensure the `CHAPTER_NAV_MENUS` environment variable is not set.

## How It Works

Chapter Navigation uses the menu ordering from MenuBuilder to create sequential navigation between pages. Pages that appear in the configured menus automatically get prev/next links based on their menu position.

### Example Setup

```markdown
---
title = "Quick Start Guide"
menu = 2.1
template = "docs"
---
```

```markdown
---
title = "Configuration Guide"
menu = 2.2
template = "docs"
---
```

**Results:**
- **Quick Start Guide** (2.1): Shows only "Next →" link to Configuration Guide
- **Configuration Guide** (2.2): Shows "← Prev" to Quick Start

## Using in Templates

The chapter navigation HTML is automatically generated. To display it in your template:

```twig
{% if features.ChapterNav.pages[source_file] is defined %}
  {% for menu_num, nav_data in features.ChapterNav.pages[source_file] %}
    {{ nav_data.html|raw }}
  {% endfor %}
{% endif %}
```

## Navigation Data Structure

Each page gets:
- `prev` - Previous page data (title, url, file) or null
- `current` - Current page data
- `next` - Next page data or null
- `html` - Pre-generated HTML for the navigation
