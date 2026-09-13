/* =========================================================
   VENDORCAT DASHBOARD
   Stable SPA + Chart.js version
========================================================= */

(function () {
    "use strict";

    window.VendorCat = window.VendorCat || {};

    /* =====================================================
       SIDEBAR
    ===================================================== */
    function toggleSidebar() {
        const sidebar = document.getElementById("sidebar");
        if (!sidebar) return;

        sidebar.classList.toggle("show");
    }

    window.toggleSidebar = toggleSidebar;

    /* =====================================================
       FULLSCREEN
    ===================================================== */
    async function toggleFullscreen() {
        try {
            if (!document.fullscreenElement) {
                if (document.documentElement.requestFullscreen) {
                    await document.documentElement.requestFullscreen();
                }
            } else if (document.exitFullscreen) {
                await document.exitFullscreen();
            }
        } catch (error) {
            console.error("Fullscreen error:", error);
        }
    }

    window.toggleFullscreen = toggleFullscreen;

    /* =====================================================
       CLOCK
    ===================================================== */
    function updateClock() {
        const dateElement = document.getElementById("currentDate");
        const timeElement = document.getElementById("currentTime");

        if (!dateElement || !timeElement) return;

        const now = new Date();

        dateElement.textContent = now.toLocaleDateString("id-ID", {
            weekday: "long",
            day: "numeric",
            month: "long",
            year: "numeric",
            timeZone: "Asia/Jakarta"
        });

        timeElement.textContent =
            now.toLocaleTimeString("id-ID", {
                hour: "2-digit",
                minute: "2-digit",
                second: "2-digit",
                hour12: false,
                timeZone: "Asia/Jakarta"
            }) + " WIB";
    }

    window.updateClock = updateClock;

    /* =====================================================
       CHART DATA
    ===================================================== */
    const chartData = {
        "7": {
            labels: ["1 Sep", "2 Sep", "3 Sep", "4 Sep", "5 Sep", "6 Sep", "7 Sep"],
            sales: [2100000, 2500000, 3800000, 3200000, 4300000, 5500000, 7200000],
            orders: [12, 15, 18, 16, 22, 25, 30]
        },
        "30": {
            labels: ["10 Agu", "15 Agu", "20 Agu", "25 Agu", "30 Agu", "2 Sep", "7 Sep"],
            sales: [18500000, 22400000, 19800000, 28600000, 31900000, 38700000, 45230000],
            orders: [68, 76, 71, 91, 103, 116, 128]
        },
        year: {
            labels: ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep"],
            sales: [32500000, 38100000, 35600000, 40200000, 43800000, 41700000, 46200000, 48900000, 45230000],
            orders: [91, 98, 94, 108, 117, 112, 121, 135, 128]
        }
    };

    const categoryData = {
        current: [28, 24, 18, 12, 10, 8],
        previous: [25, 21, 20, 14, 12, 8]
    };

    const categoryLabels = [
        "Cat Solid",
        "Cat Metallic",
        "Clear Coat",
        "Primer",
        "Thinner",
        "Warna Custom"
    ];

    const categoryColors = [
        "#2581f7",
        "#ef3340",
        "#34c98a",
        "#7543d9",
        "#f59e0b",
        "#e9b949"
    ];

    /* =====================================================
       HELPERS
    ===================================================== */
    function formatRupiah(value) {
        return "Rp " + Number(value || 0).toLocaleString("id-ID");
    }

    function destroyChart(name) {
        const instance = window[name];
        if (instance && typeof instance.destroy === "function") {
            instance.destroy();
        }
        window[name] = null;
    }

    function chartAvailable() {
        if (typeof window.Chart === "undefined") {
            console.error(
                "Chart.js tidak tersedia. Pastikan CDN Chart.js berhasil dimuat sebelum dashboard.js."
            );
            return false;
        }
        return true;
    }

    /* =====================================================
       SALES CHART
    ===================================================== */
    function initSalesChart() {
        const canvas = document.getElementById("salesChart");
        if (!canvas) return;

        if (!chartAvailable()) return;

        const periodSelect = document.getElementById("salesPeriod");
        const period = periodSelect ? periodSelect.value : "7";
        const data = chartData[period] || chartData["7"];

        destroyChart("salesChartInstance");

        window.salesChartInstance = new Chart(canvas.getContext("2d"), {
            type: "bar",
            data: {
                labels: data.labels,
                datasets: [
                    {
                        type: "bar",
                        label: "Penjualan",
                        data: data.sales,
                        backgroundColor: "#1976e8",
                        borderRadius: 6,
                        borderSkipped: false,
                        barPercentage: 0.62,
                        categoryPercentage: 0.72,
                        yAxisID: "ySales"
                    },
                    {
                        type: "line",
                        label: "Order",
                        data: data.orders,
                        borderColor: "#ef3340",
                        backgroundColor: "#ef3340",
                        borderWidth: 2.5,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: "#ef3340",
                        pointBorderColor: "#ffffff",
                        pointBorderWidth: 2,
                        tension: 0.35,
                        fill: false,
                        yAxisID: "yOrders"
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 100,
                interaction: {
                    intersect: false,
                    mode: "index"
                },
                animation: {
                    duration: 500
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                if (context.dataset.yAxisID === "yOrders") {
                                    return " Order: " + context.raw + " transaksi";
                                }
                                return " Penjualan: " + formatRupiah(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    ySales: {
                        type: "linear",
                        position: "left",
                        beginAtZero: true,
                        grid: {
                            color: "#edf1f5"
                        },
                        ticks: {
                            color: "#64748b",
                            font: {
                                size: 10
                            },
                            callback: function (value) {
                                return "Rp " + (value / 1000000).toFixed(0) + " Jt";
                            }
                        }
                    },
                    yOrders: {
                        type: "linear",
                        position: "right",
                        beginAtZero: true,
                        suggestedMax: Math.max(...data.orders) + 10,
                        grid: {
                            drawOnChartArea: false
                        },
                        ticks: {
                            color: "#ef3340",
                            font: {
                                size: 10
                            },
                            callback: function (value) {
                                return value;
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: "#64748b",
                            font: {
                                size: 10
                            }
                        }
                    }
                }
            }
        });
    }

    window.initSalesChart = initSalesChart;

    /* =====================================================
       CATEGORY CHART
    ===================================================== */
    function initCategoryChart() {
        const canvas = document.getElementById("categoryChart");
        if (!canvas) return;

        if (!chartAvailable()) return;

        const select = document.getElementById("categoryPeriod");
        const period = select ? select.value : "current";
        const data = categoryData[period] || categoryData.current;

        destroyChart("categoryChartInstance");

        window.categoryChartInstance = new Chart(canvas.getContext("2d"), {
            type: "doughnut",
            data: {
                labels: categoryLabels,
                datasets: [
                    {
                        data: data,
                        backgroundColor: categoryColors,
                        borderWidth: 0,
                        hoverOffset: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: "70%",
                animation: {
                    duration: 500
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.label + ": " + context.raw + "%";
                            }
                        }
                    }
                }
            }
        });

        updateCategoryList(data);
    }

    window.initCategoryChart = initCategoryChart;

    function updateCategoryList(data) {
        const list = document.querySelector(".category-list");
        if (!list) return;

        const items = list.querySelectorAll(":scope > div");

        items.forEach(function (item, index) {
            const value = data[index];
            if (value === undefined) return;

            const strong = item.querySelector("strong");
            if (strong) strong.textContent = value + "%";
        });
    }

    /* =====================================================
       CHART CONTROLS
    ===================================================== */
    function initChartControls() {
        const salesPeriod = document.getElementById("salesPeriod");
        const categoryPeriod = document.getElementById("categoryPeriod");

        if (salesPeriod && !salesPeriod.dataset.bound) {
            salesPeriod.addEventListener("change", initSalesChart);
            salesPeriod.dataset.bound = "true";
        }

        if (categoryPeriod && !categoryPeriod.dataset.bound) {
            categoryPeriod.addEventListener("change", initCategoryChart);
            categoryPeriod.dataset.bound = "true";
        }
    }

    /* =====================================================
       DASHBOARD
    ===================================================== */
    function initDashboard() {
        console.log("Initializing VendorCat Dashboard...");

        updateClock();

        // Beri browser satu frame untuk menyelesaikan layout SPA
        requestAnimationFrame(function () {
            initSalesChart();
            initCategoryChart();
            initChartControls();

            // Pastikan ukuran Chart.js dihitung ulang setelah DOM selesai.
            setTimeout(function () {
                if (window.salesChartInstance) {
                    window.salesChartInstance.resize();
                }
                if (window.categoryChartInstance) {
                    window.categoryChartInstance.resize();
                }
            }, 50);
        });
    }

    window.initDashboard = initDashboard;

    /* =====================================================
       CLOCK
    ===================================================== */
    setInterval(updateClock, 1000);

    /* =====================================================
       INITIAL LOAD
    ===================================================== */
    document.addEventListener("DOMContentLoaded", function () {
        if (
            document.getElementById("salesChart") ||
            document.getElementById("categoryChart")
        ) {
            initDashboard();
        }
    });

})();