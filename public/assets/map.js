(() => {
  "use strict";

  const BASE = window.GTFS_BASE || "";

  const root = document.querySelector(".map-shell");
  if (!root) return;

  const browsePanel = document.getElementById("mapBrowsePanel");
  const detailPanel = document.getElementById("mapDetailPanel");
  const backBtn = document.getElementById("mapBackToRoutes");

  const routeListEl = document.getElementById("mapRouteList");
  const routeSearchEl = document.getElementById("mapRouteSearch");
  const stopResultsEl = document.getElementById("mapStopResults");
  const routeCountEl = document.getElementById("mapRouteCount");
  const showAllBtn = document.getElementById("mapShowAll");

  const detailRouteNameEl = document.getElementById("mapDetailRouteName");
  const detailRouteSubtitleEl = document.getElementById("mapDetailRouteSubtitle");
  const directionTabsEl = document.getElementById("mapDirectionTabs");
  const infoTabsEl = document.getElementById("mapInfoTabs");
  const scheduleEl = document.getElementById("mapRouteSchedule");
  const timelineEl = document.getElementById("mapStopTimeline");
  const routeInfoEl = document.getElementById("mapRouteInfo");

  const routeTitleEl = document.getElementById("mapRouteTitle");
  const routeSubtitleEl = document.getElementById("mapRouteSubtitle");
  const loadingEl = document.getElementById("mapLoading");
  const mapEl = document.getElementById("busMap");

  if (!window.L || !mapEl) {
    if (mapEl) {
      mapEl.innerHTML =
        '<div class="map-fatal">Không tải được Leaflet. Hãy kiểm tra kết nối Internet rồi tải lại trang.</div>';
    }
    return;
  }

  const ROUTE_COLOR = "#23875d";
  const ROUTE_HALO = "#ffffff";
  const ROUTING_BASE = "https://router.project-osrm.org/route/v1/driving";
  const NOMINATIM_BASE = "https://nominatim.openstreetmap.org/reverse";
  const MAX_POINTS_PER_REQUEST = 40;

  let routes = [];
  let filteredRoutes = [];
  let currentRouteId = "";
  let currentRouteData = null;
  let currentPatternIndex = 0;
  let currentMarkers = [];
  let currentActiveTab = "stops";
  let matchedStops = [];
  let searchSeq = 0;
  let searchTimer = null;
  const routeStopHint = new Map();

  const routingCache = new Map();
  const addressCache = new Map();

  const map = L.map("busMap", {
    zoomControl: true,
    preferCanvas: true,
  }).setView([21.0285, 105.8542], 12);

  L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: "&copy; OpenStreetMap contributors",
  }).addTo(map);

  const routeLayer = L.layerGroup().addTo(map);
  const allRoutesLayer = L.layerGroup().addTo(map);
  const searchLayer = L.layerGroup().addTo(map);

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function validStop(stop) {
    const lat = Number(stop?.lat);
    const lon = Number(stop?.lon);
    return (
      Number.isFinite(lat) &&
      Number.isFinite(lon) &&
      lat >= -90 &&
      lat <= 90 &&
      lon >= -180 &&
      lon <= 180
    );
  }

  function routeDisplayName(route) {
    const shortName = String(route?.short_name || "").trim();
    const longName = String(route?.long_name || "").trim();
    if (shortName && longName) return `${shortName} · ${longName}`;
    return shortName || longName || String(route?.id || "Tuyến");
  }

  function setLoading(on, message = "Đang tải dữ liệu tuyến...") {
    if (!loadingEl) return;
    loadingEl.hidden = !on;
    const text = loadingEl.querySelector("span:last-child");
    if (on && text) text.textContent = message;
  }

  async function fetchJson(url) {
    const response = await fetch(url, {
      headers: { Accept: "application/json" },
    });

    const payload = await response.json();

    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || "Không tải được dữ liệu.");
    }

    return payload;
  }

  function showBrowsePanel() {
    stopTimelineWaits();
    detailPanel.hidden = true;
    browsePanel.hidden = false;
    setTimeout(() => map.invalidateSize(), 50);
  }

  function showDetailPanel() {
    browsePanel.hidden = true;
    detailPanel.hidden = false;
    setTimeout(() => map.invalidateSize(), 50);
  }

  function setActiveInfoTab(name) {
    currentActiveTab = name;

    infoTabsEl.querySelectorAll(".map-info-tab").forEach((button) => {
      const active = button.dataset.tab === name;
      button.classList.toggle("active", active);
      button.setAttribute("aria-selected", active ? "true" : "false");
    });

    document.querySelectorAll(".map-tab-panel").forEach((panel) => {
      panel.hidden = panel.dataset.panel !== name;
    });
  }

  function renderRouteList() {
    routeListEl.innerHTML = "";
    routeCountEl.textContent = `${filteredRoutes.length} tuyến`;

    if (!filteredRoutes.length) {
      routeListEl.innerHTML =
        '<div class="map-empty">Không tìm thấy tuyến phù hợp.</div>';
      return;
    }

    const fragment = document.createDocumentFragment();

    filteredRoutes.forEach((route) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className =
        "map-route-item" + (route.id === currentRouteId ? " active" : "");

      button.innerHTML = `
        <span class="map-route-badge">${escapeHtml(route.short_name || route.id)}</span>
        <span class="map-route-copy">
          <b>${escapeHtml(routeDisplayName(route))}</b>
          <small>${
            routeStopHint.has(route.id)
              ? "Đi qua " + escapeHtml(routeStopHint.get(route.id))
              : Number(route.trip_count || 0) + " chuyến"
          }</small>
        </span>
        <span class="map-route-chevron" aria-hidden="true">›</span>
      `;

      button.addEventListener("click", () => loadRoute(route.id));
      fragment.appendChild(button);
    });

    routeListEl.appendChild(fragment);
  }

  function renderDirectionTabs(patterns) {
    directionTabsEl.innerHTML = "";

    if (!patterns?.length) return;

    patterns.forEach((pattern, index) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className =
        "map-direction-btn" + (index === currentPatternIndex ? " active" : "");

      let label = `Hướng ${index + 1}`;
      if (pattern.direction_id === 0) label = "Xem lượt đi";
      if (pattern.direction_id === 1) label = "Xem lượt về";

      button.textContent = label;
      button.title = pattern.headsign || label;

      button.addEventListener("click", async () => {
        currentPatternIndex = index;
        renderDirectionTabs(patterns);
        await drawCurrentPattern();
      });

      directionTabsEl.appendChild(button);
    });
  }

  function currentPattern() {
    const patterns = currentRouteData?.patterns || [];
    return patterns[currentPatternIndex] || patterns[0] || null;
  }

  function renderStopTimeline(pattern) {
    timelineEl.innerHTML = "";

    const stops = pattern?.stops || [];
    if (!stops.length) {
      timelineEl.innerHTML = '<div class="map-empty">Không có trạm dừng.</div>';
      return;
    }

    const fragment = document.createDocumentFragment();

    stops.forEach((stop, index) => {
      const item = document.createElement("button");
      item.type = "button";
      item.className = "map-stop-item";

      const first = index === 0;
      const last = index === stops.length - 1;

      item.innerHTML = `
        <span class="map-stop-rail ${first ? "first" : ""} ${last ? "last" : ""}">
          <i></i>
        </span>
        <span class="map-stop-copy">
          <b>${escapeHtml(stop.name)}</b>
          <small>${escapeHtml(stop.arrival || "—")} → ${escapeHtml(stop.departure || "—")}</small>
        </span>
        <span class="wait-chip loading" data-wait-stop="${escapeHtml(stop.id)}" data-last="${last ? "1" : "0"}">…</span>
      `;

      item.addEventListener("click", () => focusStop(index));
      fragment.appendChild(item);
    });

    timelineEl.appendChild(fragment);
    startTimelineWaits(pattern);
  }

  function renderSchedule(pattern) {
    const stops = pattern?.stops || [];

    if (!stops.length) {
      scheduleEl.innerHTML = '<div class="map-empty">Không có biểu đồ giờ.</div>';
      return;
    }

    scheduleEl.innerHTML = `
      <div class="map-schedule-head">
        <span>Trạm</span>
        <span>Đến</span>
        <span>Rời</span>
      </div>
      ${stops
        .map(
          (stop, index) => `
            <button class="map-schedule-row" type="button" data-stop-index="${index}">
              <span>${index + 1}. ${escapeHtml(stop.name)}</span>
              <b>${escapeHtml(stop.arrival || "—")}</b>
              <b>${escapeHtml(stop.departure || "—")}</b>
            </button>
          `,
        )
        .join("")}
    `;

    scheduleEl.querySelectorAll(".map-schedule-row").forEach((row) => {
      row.addEventListener("click", () => {
        focusStop(Number(row.dataset.stopIndex));
      });
    });
  }

  function renderRouteInfo(route, pattern) {
    const stops = pattern?.stops || [];
    const first = stops[0];
    const last = stops[stops.length - 1];

    routeInfoEl.innerHTML = `
      <div class="map-info-card">
        <span>Mã tuyến</span>
        <b>${escapeHtml(route.short_name || route.id)}</b>
      </div>
      <div class="map-info-card">
        <span>Tên tuyến</span>
        <b>${escapeHtml(route.long_name || routeDisplayName(route))}</b>
      </div>
      <div class="map-info-card">
        <span>Hướng đến</span>
        <b>${escapeHtml(pattern?.headsign || "Không có thông tin")}</b>
      </div>
      <div class="map-info-card">
        <span>Số trạm</span>
        <b>${stops.length}</b>
      </div>
      <div class="map-info-card">
        <span>Điểm đầu</span>
        <b>${escapeHtml(first?.name || "—")}</b>
      </div>
      <div class="map-info-card">
        <span>Điểm cuối</span>
        <b>${escapeHtml(last?.name || "—")}</b>
      </div>
      <div class="map-info-card">
        <span>Trip đại diện</span>
        <b>${escapeHtml(pattern?.trip_id || "—")}</b>
      </div>
    `;
  }

  async function reverseGeocode(stop, addressEl) {
    const key = `${Number(stop.lat).toFixed(6)},${Number(stop.lon).toFixed(6)}`;

    if (addressCache.has(key)) {
      addressEl.textContent = addressCache.get(key);
      return;
    }

    addressEl.textContent = "Đang tra địa chỉ gần nhất...";

    try {
      const url =
        `${NOMINATIM_BASE}?format=jsonv2` +
        `&lat=${encodeURIComponent(stop.lat)}` +
        `&lon=${encodeURIComponent(stop.lon)}` +
        "&zoom=18&addressdetails=0&accept-language=vi";

      const response = await fetch(url, {
        headers: { Accept: "application/json" },
      });

      if (!response.ok) throw new Error("reverse geocode failed");

      const data = await response.json();
      const address = data.display_name || "Chưa xác định được địa chỉ.";

      addressCache.set(key, address);
      addressEl.textContent = address;
    } catch (error) {
      addressEl.textContent =
        "Không lấy được địa chỉ. Tọa độ trạm vẫn được giữ theo GTFS.";
    }
  }

  // ---------- Thời gian chờ ----------
  const WAIT_REFRESH_MS = 20000;
  let timelineWaitTimer = null;
  let timelineWaitSeq = 0;

  async function fetchWaits(stopId, scope) {
    let url = `${BASE}/api/map/waits?stop_id=${encodeURIComponent(stopId)}&limit=6`;
    if (!scope.all && scope.routeId) {
      url += `&route_id=${encodeURIComponent(scope.routeId)}`;
      if (scope.directionId !== null && scope.directionId !== undefined) {
        url += `&direction_id=${encodeURIComponent(scope.directionId)}`;
      }
    }
    return fetchJson(url);
  }

  function waitPanelHtml(data, scope) {
    const rows = (data.arrivals || [])
      .map((a) => {
        const soon = a.minutes <= 3;
        const route = scope.all
          ? `<span class="wait-route">${escapeHtml(a.route_name.split(" ")[0] || a.route_id)}</span>`
          : "";
        const day = a.date ? `<small>${escapeHtml(a.date)}</small>` : "";
        return `
          <div class="wait-row${soon ? " soon" : ""}">
            <span class="wait-clock"><b>${escapeHtml(a.clock)}</b>${day}</span>
            <span class="wait-info">${route}<span>${escapeHtml(a.headsign || "—")}</span></span>
            <span class="wait-eta">${escapeHtml(a.text)}</span>
          </div>`;
      })
      .join("");

    const body = rows
      ? rows
      : `<p class="wait-empty">${escapeHtml(data.message || "Chưa có chuyến nào sắp tới.")}</p>`;

    return `
      <div class="wait-head">
        <span>Bây giờ: <b>${escapeHtml(data.now)}</b></span>
        <span>${scope.all ? "Mọi tuyến qua trạm" : "Tuyến đang xem"}</span>
      </div>
      ${body}
      ${
        scope.routeId
          ? `<button type="button" class="wait-scope" data-wait-scope>${
              scope.all ? "Chỉ xem tuyến này" : "Xem mọi tuyến qua trạm này"
            }</button>`
          : ""
      }`;
  }

  /** Gắn nút "Xem thời gian chờ" vào popup của một trạm; trả về hàm dọn dẹp. */
  function attachWaitPanel(popup, stop, scope, autoOpen = false) {
    const el = popup.getElement();
    const btn = el?.querySelector("[data-wait-btn]");
    const panel = el?.querySelector("[data-wait-panel]");
    if (!btn || !panel) return () => {};

    let timer = null;
    let open = false;

    const load = async () => {
      try {
        const data = await fetchWaits(stop.id, scope);
        panel.innerHTML = waitPanelHtml(data, scope);
      } catch (error) {
        panel.innerHTML = `<p class="wait-empty">Không tải được thời gian chờ: ${escapeHtml(error.message)}</p>`;
      }
      popup.update();
    };

    const setOpen = (value) => {
      open = value;
      panel.hidden = !open;
      btn.classList.toggle("on", open);
      btn.textContent = open ? "Ẩn thời gian chờ" : "Xem thời gian chờ";
      clearInterval(timer);
      if (open) {
        panel.innerHTML = '<p class="wait-empty"><span class="map-spinner"></span> Đang tải thời gian chờ...</p>';
        load();
        timer = setInterval(load, WAIT_REFRESH_MS);
      }
      popup.update();
    };

    btn.addEventListener("click", () => setOpen(!open));
    panel.addEventListener("click", (event) => {
      if (!event.target.closest("[data-wait-scope]")) return;
      scope.all = !scope.all;
      setOpen(true);
    });

    if (autoOpen) setOpen(true);
    return () => clearInterval(timer);
  }

  const WAIT_BLOCK_HTML = `
    <button type="button" class="wait-btn" data-wait-btn>Xem thời gian chờ</button>
    <div class="wait-panel" data-wait-panel hidden></div>`;

  function stopTimelineWaits() {
    timelineWaitSeq += 1;
    clearInterval(timelineWaitTimer);
    timelineWaitTimer = null;
  }

  /** Điền nhãn "còn X phút" vào từng trạm trong danh sách trạm của tuyến. */
  function startTimelineWaits(pattern) {
    stopTimelineWaits();
    const seq = timelineWaitSeq;
    const stops = pattern?.stops || [];
    if (!stops.length || !currentRouteId) return;

    const ids = [...new Set(stops.map((s) => s.id))];
    const dir = pattern.direction_id;

    const load = async () => {
      let url =
        `${BASE}/api/map/waits-batch?route_id=${encodeURIComponent(currentRouteId)}` +
        `&stops=${encodeURIComponent(ids.join(","))}`;
      if (dir !== null && dir !== undefined) url += `&direction_id=${encodeURIComponent(dir)}`;

      try {
        const data = await fetchJson(url);
        if (seq !== timelineWaitSeq) return; // đã đổi tuyến/chiều
        timelineEl.querySelectorAll("[data-wait-stop]").forEach((chip) => {
          const w = data.waits?.[chip.dataset.waitStop];
          const isLast = chip.dataset.last === "1";
          chip.className = "wait-chip";
          if (w) {
            chip.textContent = w.text;
            chip.title = `Chuyến ${w.clock}${w.date ? " ngày " + w.date : ""}`;
            if (w.minutes <= 3) chip.classList.add("soon");
          } else {
            chip.textContent = isLast ? "Điểm cuối" : "Hết chuyến";
            chip.classList.add("none");
          }
        });
      } catch (_) {
        /* giữ nguyên nhãn cũ nếu mạng lỗi */
      }
    };

    load();
    timelineWaitTimer = setInterval(load, WAIT_REFRESH_MS);
  }

  function createStopMarker(stop, index, total) {
    const first = index === 0;
    const last = index === total - 1;

    const marker = L.circleMarker([Number(stop.lat), Number(stop.lon)], {
      radius: first || last ? 7 : 5,
      color: ROUTE_COLOR,
      weight: 3,
      fillColor: "#ffffff",
      fillOpacity: 1,
      opacity: 1,
    });

    const popupId = `mapStopAddress_${index}`;

    marker.bindPopup(`
      <div class="map-popup-detail">
        <span class="popup-tag ${first ? "start" : last ? "end" : ""}">
          ${first ? "Điểm đầu" : last ? "Điểm cuối" : "Trạm dừng"}
        </span>
        <h3>${escapeHtml(stop.name)}</h3>
        <dl>
          <div><dt>Mã trạm</dt><dd>${escapeHtml(stop.id)}</dd></div>
          <div><dt>Tọa độ</dt><dd>${Number(stop.lat).toFixed(6)}, ${Number(stop.lon).toFixed(6)}</dd></div>
          <div><dt>Giờ đến</dt><dd>${escapeHtml(stop.arrival || "—")}</dd></div>
          <div><dt>Giờ rời</dt><dd>${escapeHtml(stop.departure || "—")}</dd></div>
        </dl>
        <div class="map-popup-address">
          <b>Địa chỉ gần nhất</b>
          <p id="${popupId}">Bấm vào trạm để tra địa chỉ...</p>
        </div>
        ${WAIT_BLOCK_HTML}
      </div>
    `, { minWidth: 280 });

    let stopWaits = () => {};

    marker.on("popupopen", (event) => {
      const addressEl = document.getElementById(popupId);
      if (addressEl) reverseGeocode(stop, addressEl);

      const pattern = currentPattern();
      stopWaits = attachWaitPanel(event.popup, stop, {
        routeId: currentRouteId,
        directionId: pattern?.direction_id ?? null,
        all: false,
      });
    });

    marker.on("popupclose", () => stopWaits());

    marker.bindTooltip(`${index + 1}. ${stop.name}`, {
      direction: "top",
      offset: [0, -6],
    });

    return marker;
  }

  function focusStop(index) {
    const pattern = currentPattern();
    const stop = pattern?.stops?.[index];
    const marker = currentMarkers[index];

    if (!stop || !marker) return;

    map.flyTo([Number(stop.lat), Number(stop.lon)], Math.max(map.getZoom(), 16), {
      duration: 0.7,
    });

    marker.openPopup();
  }

  function splitIntoRoutingChunks(stops) {
    if (stops.length <= MAX_POINTS_PER_REQUEST) return [stops];

    const chunks = [];
    let start = 0;

    while (start < stops.length - 1) {
      const end = Math.min(start + MAX_POINTS_PER_REQUEST, stops.length);
      const chunk = stops.slice(start, end);

      if (chunk.length >= 2) chunks.push(chunk);
      start = end - 1;
    }

    return chunks;
  }

  async function requestRoadGeometry(stops) {
    const coordinates = stops
      .map((stop) => `${Number(stop.lon)},${Number(stop.lat)}`)
      .join(";");

    const response = await fetch(
      `${ROUTING_BASE}/${coordinates}?overview=full&geometries=geojson&steps=false&alternatives=false`,
      { headers: { Accept: "application/json" } },
    );

    if (!response.ok) {
      throw new Error(`Routing HTTP ${response.status}`);
    }

    const data = await response.json();

    if (
      data.code !== "Ok" ||
      !data.routes?.[0]?.geometry?.coordinates
    ) {
      throw new Error(data.message || "Không tìm được đường phù hợp.");
    }

    return data.routes[0].geometry.coordinates.map(([lon, lat]) => [lat, lon]);
  }

  async function buildRoadGeometry(stops, cacheKey) {
    const cleanStops = stops.filter(validStop);

    if (cleanStops.length < 2) {
      return {
        points: cleanStops.map((stop) => [Number(stop.lat), Number(stop.lon)]),
        routed: false,
      };
    }

    if (routingCache.has(cacheKey)) {
      return routingCache.get(cacheKey);
    }

    const task = (async () => {
      try {
        const chunks = splitIntoRoutingChunks(cleanStops);
        const points = [];

        for (let index = 0; index < chunks.length; index += 1) {
          const part = await requestRoadGeometry(chunks[index]);
          if (index > 0 && part.length) part.shift();
          points.push(...part);
        }

        if (points.length >= 2) {
          return { points, routed: true };
        }
      } catch (error) {
        console.warn("OSRM routing thất bại:", error);
      }

      return {
        points: cleanStops.map((stop) => [Number(stop.lat), Number(stop.lon)]),
        routed: false,
      };
    })();

    routingCache.set(cacheKey, task);
    return task;
  }

  function shapeGeometry(pattern) {
    const points = Array.isArray(pattern?.shape_points)
      ? pattern.shape_points
          .filter((point) => {
            const lat = Number(point?.lat);
            const lon = Number(point?.lon);
            return Number.isFinite(lat) && Number.isFinite(lon);
          })
          .map((point) => [Number(point.lat), Number(point.lon)])
      : [];

    if (points.length < 2) return null;

    // Shape lệch khỏi trạm (dữ liệu cũ/sai tọa độ) thì bỏ, để dựng đường lại theo trạm.
    const stops = (pattern?.stops || []).filter(validStop);
    const ends = [stops[0], stops[stops.length - 1]].filter(Boolean);
    const MAX_GAP_M = 500;
    const nearShape = (stop) =>
      points.some(
        ([lat, lon]) =>
          map.distance([Number(stop.lat), Number(stop.lon)], [lat, lon]) <= MAX_GAP_M,
      );

    if (ends.length && !ends.every(nearShape)) {
      console.warn("Shape không khớp vị trí trạm, bỏ qua shape.");
      return null;
    }

    return points;
  }

  async function geometryForPattern(pattern, cacheKey) {
    const shapePoints = shapeGeometry(pattern);
    if (shapePoints) {
      return {
        points: shapePoints,
        source: "shape",
        routed: true,
      };
    }

    const fallback = await buildRoadGeometry(pattern.stops || [], cacheKey);
    return {
      ...fallback,
      source: fallback.routed ? "routing" : "stops",
    };
  }

  async function drawCurrentPattern() {
    routeLayer.clearLayers();
    allRoutesLayer.clearLayers();
    currentMarkers = [];

    const pattern = currentPattern();
    if (!pattern?.stops?.length) {
      routeSubtitleEl.textContent = "Tuyến này chưa có tọa độ trạm.";
      return;
    }

    const route = currentRouteData.route;
    const title = routeDisplayName(route);
    const cacheKey = `${route.id}|${pattern.trip_id}|${pattern.direction_id ?? "null"}`;

    detailRouteNameEl.textContent = route.short_name
      ? `Tuyến ${route.short_name}`
      : title;
    detailRouteSubtitleEl.textContent =
      pattern.headsign || `${pattern.stop_count} trạm`;

    routeTitleEl.textContent = title;
    routeSubtitleEl.textContent = "Đang dựng lộ trình theo mạng đường...";

    renderStopTimeline(pattern);
    renderSchedule(pattern);
    renderRouteInfo(route, pattern);

    setLoading(true, "Đang dựng lộ trình theo đường thật...");

    try {
      const geometry = await geometryForPattern(pattern, cacheKey);

      L.polyline(geometry.points, {
        color: ROUTE_HALO,
        weight: 8,
        opacity: 0.78,
        lineJoin: "round",
        lineCap: "round",
      }).addTo(routeLayer);

      const line = L.polyline(geometry.points, {
        color: ROUTE_COLOR,
        weight: 4,
        opacity: 0.98,
        lineJoin: "round",
        lineCap: "round",
      }).addTo(routeLayer);

      pattern.stops.forEach((stop, index) => {
        if (!validStop(stop)) return;

        const marker = createStopMarker(stop, index, pattern.stops.length);
        marker.addTo(routeLayer);
        currentMarkers[index] = marker;
      });

      const destination = pattern.headsign ? ` → ${pattern.headsign}` : "";
      routeSubtitleEl.textContent =
        `${pattern.stop_count} trạm${destination}` +
        (geometry.source === "shape"
          ? " · Đường GTFS shapes.txt"
          : geometry.source === "routing"
            ? " · Lộ trình suy ra theo mạng đường"
            : " · Chưa có shape, dùng đường dự phòng");

      if (geometry.points.length > 1) {
        map.fitBounds(line.getBounds(), {
          padding: [35, 35],
          maxZoom: 16,
        });
      }
    } finally {
      setLoading(false);
    }
  }

  async function loadRoute(routeId) {
    if (!routeId) return;

    searchLayer.clearLayers();

    currentRouteId = routeId;
    currentPatternIndex = 0;
    renderRouteList();
    showDetailPanel();
    setActiveInfoTab("stops");
    setLoading(true, "Đang tải tuyến...");

    try {
      const payload = await fetchJson(
        `${BASE}/api/map/route?route_id=${encodeURIComponent(routeId)}`,
      );

      currentRouteData = payload.data;
      renderDirectionTabs(currentRouteData.patterns || []);
      await drawCurrentPattern();
    } catch (error) {
      currentRouteData = null;
      routeLayer.clearLayers();
      detailRouteNameEl.textContent = "Không tải được tuyến";
      detailRouteSubtitleEl.textContent = error.message;
      routeTitleEl.textContent = "Không tải được tuyến";
      routeSubtitleEl.textContent = error.message;
    } finally {
      setLoading(false);
    }
  }

  async function fetchRouteData(routeId) {
    const payload = await fetchJson(
      `${BASE}/api/map/route?route_id=${encodeURIComponent(routeId)}`,
    );
    return payload.data;
  }

  async function showAllRoutes() {
    searchLayer.clearLayers();
    routeLayer.clearLayers();
    allRoutesLayer.clearLayers();
    currentRouteId = "";
    currentRouteData = null;
    renderRouteList();

    const selected = routes.slice(0, Math.min(routes.length, 10));
    const bounds = [];

    showBrowsePanel();
    routeTitleEl.textContent = "Tất cả tuyến";
    routeSubtitleEl.textContent = "Bấm vào một tuyến bên trái để xem lộ trình chi tiết.";
    setLoading(true, "Đang dựng các tuyến...");

    try {
      for (let index = 0; index < selected.length; index += 1) {
        const route = selected[index];

        try {
          const data = await fetchRouteData(route.id);
          const pattern = data.patterns?.[0];
          if (!pattern?.stops?.length) continue;

          const geometry = await geometryForPattern(
            pattern,
            `${route.id}|${pattern.trip_id}`,
          );

          bounds.push(...geometry.points);

          L.polyline(geometry.points, {
            color: [
              "#23875d",
              "#3779d6",
              "#8155c7",
              "#d47f26",
              "#c85050",
              "#258ca8",
            ][index % 6],
            weight: 3,
            opacity: 0.75,
          })
            .bindTooltip(routeDisplayName(route), { sticky: true })
            .on("click", () => loadRoute(route.id))
            .addTo(allRoutesLayer);
        } catch (error) {
          console.warn(`Không vẽ được tuyến ${route.id}:`, error);
        }
      }

      if (bounds.length) {
        map.fitBounds(bounds, { padding: [25, 25], maxZoom: 13 });
      }
    } finally {
      setLoading(false);
    }
  }

  async function loadRoutes() {
    setLoading(true, "Đang tải danh sách tuyến...");

    try {
      const payload = await fetchJson(`${BASE}/api/map/routes`);
      routes = Array.isArray(payload.routes) ? payload.routes : [];
      filteredRoutes = [...routes];

      renderRouteList();

      routeTitleEl.textContent = "Chọn một tuyến";
      routeSubtitleEl.textContent =
        "Danh sách tuyến nằm ở bên trái. Chọn tuyến để xem chi tiết.";

      if (!routes.length) {
        routeListEl.innerHTML =
          '<div class="map-empty">GTFS hiện tại chưa có tuyến để hiển thị.</div>';
      }
    } catch (error) {
      routeListEl.innerHTML =
        `<div class="map-empty error">${escapeHtml(error.message)}</div>`;
      routeCountEl.textContent = "Không tải được dữ liệu";
    } finally {
      setLoading(false);
    }
  }

  // Bỏ dấu để gõ "times", "ha dong"... vẫn tìm được.
  function fold(value) {
    return String(value ?? "")
      .toLocaleLowerCase("vi")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/đ/g, "d")
      .trim();
  }

  function clearStopSearch() {
    matchedStops = [];
    routeStopHint.clear();
    searchLayer.clearLayers();
    stopResultsEl.hidden = true;
    stopResultsEl.innerHTML = "";
  }

  function applyRouteFilter() {
    const query = fold(routeSearchEl.value);
    if (!query) {
      filteredRoutes = [...routes];
      return;
    }

    const byText = (route) =>
      fold([route.id, route.short_name, route.long_name, route.name].join(" ")).includes(
        query.replace(/^(tuyen so|tuyen)\s+/, ""),
      );

    const viaStop = new Set();
    routeStopHint.clear();
    matchedStops.forEach((stop) => {
      stop.routes.forEach((routeId) => {
        viaStop.add(routeId);
        if (!routeStopHint.has(routeId)) routeStopHint.set(routeId, stop.name);
      });
    });

    filteredRoutes = routes.filter(
      (route) => byText(route) || viaStop.has(route.id),
    );
  }

  function focusSearchStop(stop) {
    searchLayer.clearLayers();
    const marker = L.circleMarker([stop.lat, stop.lon], {
      radius: 9,
      color: "#ffffff",
      weight: 3,
      fillColor: "#6d8cff",
      fillOpacity: 1,
    }).addTo(searchLayer);
    marker.bindTooltip(escapeHtml(stop.name), { permanent: true, direction: "top", offset: [0, -8] });
    marker.bindPopup(
      `<div class="map-popup-detail"><span class="popup-tag">Trạm dừng</span><h3>${escapeHtml(stop.name)}</h3>${WAIT_BLOCK_HTML}</div>`,
      { minWidth: 280 },
    );
    let searchWaits = () => {};
    marker.on("popupopen", (event) => {
      searchWaits = attachWaitPanel(event.popup, stop, { routeId: "", directionId: null, all: true }, true);
    });
    marker.on("popupclose", () => searchWaits());
    map.setView([stop.lat, stop.lon], 16);

    // Chỉ hiện các tuyến đi qua trạm này.
    const ids = new Set(stop.routes);
    routeStopHint.clear();
    ids.forEach((id) => routeStopHint.set(id, stop.name));
    filteredRoutes = routes.filter((route) => ids.has(route.id));
    renderRouteList();
  }

  function renderStopResults() {
    if (!matchedStops.length) {
      stopResultsEl.hidden = true;
      stopResultsEl.innerHTML = "";
      return;
    }

    stopResultsEl.hidden = false;
    stopResultsEl.innerHTML = '<div class="map-dest-title">Điểm đến</div>';

    matchedStops.slice(0, 8).forEach((stop) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "map-dest-item";
      button.innerHTML = `
        <span class="map-dest-pin" aria-hidden="true">●</span>
        <span class="map-dest-copy">
          <b>${escapeHtml(stop.name)}</b>
          <small>${stop.routes.length} tuyến đi qua</small>
        </span>`;
      button.addEventListener("click", () => focusSearchStop(stop));
      stopResultsEl.appendChild(button);
    });
  }

  function drawStopMarkers() {
    searchLayer.clearLayers();
    if (!matchedStops.length) return;

    const bounds = [];
    matchedStops.forEach((stop) => {
      const marker = L.circleMarker([stop.lat, stop.lon], {
        radius: 8,
        color: "#ffffff",
        weight: 2,
        fillColor: "#6d8cff",
        fillOpacity: 0.95,
      }).addTo(searchLayer);
      marker.bindTooltip(escapeHtml(stop.name), { direction: "top" });
      marker.on("click", () => focusSearchStop(stop));
      bounds.push([stop.lat, stop.lon]);
    });

    if (bounds.length === 1) map.setView(bounds[0], 16);
    else map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
  }

  async function runSearch() {
    const raw = routeSearchEl.value.trim();
    const seq = ++searchSeq;

    if (!raw) {
      clearStopSearch();
      applyRouteFilter();
      renderRouteList();
      return;
    }

    // Lọc theo tên/số tuyến ngay lập tức, rồi bổ sung theo điểm đến.
    applyRouteFilter();
    renderRouteList();

    try {
      const payload = await fetchJson(
        `${BASE}/api/map/search?q=${encodeURIComponent(raw)}`,
      );
      if (seq !== searchSeq) return; // đã có lần gõ mới hơn

      matchedStops = Array.isArray(payload.stops) ? payload.stops : [];
      renderStopResults();
      drawStopMarkers();
      applyRouteFilter();
      renderRouteList();
    } catch (error) {
      if (seq !== searchSeq) return;
      clearStopSearch();
    }
  }

  routeSearchEl.addEventListener("input", () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(runSearch, 250);
  });

  showAllBtn.addEventListener("click", showAllRoutes);
  backBtn.addEventListener("click", showBrowsePanel);

  infoTabsEl.addEventListener("click", (event) => {
    const button = event.target.closest(".map-info-tab");
    if (!button) return;
    setActiveInfoTab(button.dataset.tab);
  });

  window.addEventListener("resize", () => map.invalidateSize());

  setActiveInfoTab("stops");
  showBrowsePanel();
  setTimeout(() => map.invalidateSize(), 200);
  loadRoutes();
})();
