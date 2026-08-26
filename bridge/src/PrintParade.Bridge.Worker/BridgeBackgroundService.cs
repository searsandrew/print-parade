using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using PrintParade.Bridge.Core;

namespace PrintParade.Bridge.Worker;

public sealed class BridgeBackgroundService(
    PrintJobProcessor processor,
    BridgeApiClient bridgeClient,
    BridgeConfiguration configuration,
    IBridgeStatusSink statusSink,
    ILogger<BridgeBackgroundService> logger) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        logger.LogInformation("Print Parade bridge started.");
        statusSink.Started(configuration.ServerUrl);
        var firstConnection = true;

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                if (firstConnection)
                {
                    var heartbeat = await bridgeClient.SendHeartbeatAsync(stoppingToken);
                    statusSink.Connected(heartbeat.BridgeId);
                    firstConnection = false;
                }

                var processedJob = await processor.ProcessNextAsync(stoppingToken);
                statusSink.Polled(processedJob);

                if (processedJob)
                {
                    logger.LogInformation("A print job was processed.");
                }

                await Task.Delay(TimeSpan.FromSeconds(processedJob ? 0 : 2), stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception exception) when (exception is HttpRequestException or BridgeProtocolException)
            {
                logger.LogWarning(exception, "Print Parade is temporarily unavailable.");
                statusSink.Warning(exception.Message);
                await DelayAfterFailureAsync(stoppingToken);
            }
            catch (Exception exception)
            {
                logger.LogCritical(exception, "The Print Parade bridge stopped unexpectedly.");
                Environment.Exit(1);
            }
        }

        logger.LogInformation("Print Parade bridge stopped.");
        statusSink.Stopped();
    }

    private static async Task DelayAfterFailureAsync(CancellationToken cancellationToken)
    {
        try
        {
            await Task.Delay(TimeSpan.FromSeconds(5), cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
        }
    }
}
