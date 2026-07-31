from __future__ import annotations

import datetime
import os

project = "Omnibus"
author = "Infocyph"
year_now = datetime.date.today().strftime("%Y")
copyright = f"2020-{year_now}, {author}"
version = os.environ.get("READTHEDOCS_VERSION", "latest")
release = version
language = "en"
root_doc = "index"

extensions = [
    "sphinx.ext.autosectionlabel",
    "sphinx_copybutton",
    "sphinx_design",
    "sphinxcontrib.phpdomain",
]
autosectionlabel_prefix_document = True

# Focused PHP examples intentionally omit the opening tag. Parse them as
# inline PHP so Pygments applies PHP token highlighting instead of plain text.
highlight_options = {
    "php": {
        "startinline": True,
    },
}

html_theme = "sphinx_book_theme"
html_theme_options = {
    "repository_url": "https://github.com/infocyph/Omnibus",
    "repository_branch": "main",
    "path_to_docs": "docs",
    "use_repository_button": True,
    "use_issues_button": True,
    "use_download_button": True,
    "home_page_in_toc": True,
    "show_toc_level": 2,
}

templates_path = ["_templates"]
html_static_path = ["_static"]
html_css_files = ["theme.css"]
html_title = f"Omnibus - {version} Documentation"
html_show_sourcelink = True
html_show_sphinx = False
html_last_updated_fmt = "%Y-%m-%d"
