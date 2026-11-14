from fastapi import FastAPI

app = FastAPI(title="Inventory")


@app.get("/")
def index():
    return {"ok": True}
