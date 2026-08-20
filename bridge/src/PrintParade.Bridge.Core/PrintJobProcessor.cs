namespace PrintParade.Bridge.Core;

public sealed class PrintJobProcessor(BridgeApiClient bridgeClient, IPrintSpooler printSpooler)
{
    public async Task<bool> ProcessNextAsync(CancellationToken cancellationToken = default)
    {
        var job = await bridgeClient.ClaimNextJobAsync(cancellationToken);

        if (job is null)
        {
            return false;
        }

        if (!string.Equals(job.Language, "zpl", StringComparison.OrdinalIgnoreCase))
        {
            await bridgeClient.FailJobAsync(
                job,
                $"The bridge does not support the '{job.Language}' printer language.",
                cancellationToken);

            return true;
        }

        try
        {
            await printSpooler.PrintAsync(job, cancellationToken);
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            await bridgeClient.FailJobAsync(job, PrintFailureMessage(exception), cancellationToken);

            return true;
        }

        await bridgeClient.CompleteJobAsync(job, cancellationToken);

        return true;
    }

    private static string PrintFailureMessage(Exception exception)
    {
        const string Prefix = "Windows could not spool the print job: ";
        const int MaximumApiMessageLength = 2000;
        var message = Prefix + exception.Message;

        return message.Length <= MaximumApiMessageLength
            ? message
            : message[..MaximumApiMessageLength];
    }
}
