import asyncio
import sys
import json
from playwright.async_api import async_playwright

async def fetch_description(url: str):
    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=True
        )
        context = await browser.new_context(
            user_agent=(
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                "AppleWebKit/537.36 (KHTML, like Gecko) "
                "Chrome/128.0.0.0 Safari/537.36"
            ),
            locale="zh‑CN",
            viewport={"width": 1920, "height": 1080},
        )
        await context.add_init_script(
            "Object.defineProperty(navigator,'webdriver',{get:()=>undefined})"
        )
        page = await context.new_page()
        try:
            await page.goto(url, wait_until="domcontentloaded", timeout=30000)
            el = page.locator(".encyclopedia-description").first
            await el.wait_for(state="visible", timeout=15000)
            text = await el.inner_text()
            return {"ok": True, "data": text.strip()}
        except Exception as e:
            return {"ok": False, "error": str(e)}
        finally:
            await browser.close()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"ok":False,"error":"missing url argument"}, ensure_ascii=False))
        sys.exit(1)
    target_url = sys.argv[1]
    result = asyncio.run(fetch_description(target_url))
    print(json.dumps(result, ensure_ascii=False))
